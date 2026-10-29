<?php

namespace App\Livewire\Campaigns;

use App\Models\AiGeneration;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\Workspace;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\AiUsage;
use App\Services\Ai\CopyExamples;
use App\Services\Brand\BrandGuide;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\MessageRenderer;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('캠페인 작성')]
class Form extends Component
{
    public ?int $campaignId = null;

    public string $name = '';

    public string $channel = 'email';

    public string $subject = '';

    public string $body = '';

    public int|string|null $segment_id = '';

    /** datetime-local 입력값 (Y-m-d\TH:i) */
    public string $scheduled_at = '';

    public bool $confirmingSend = false;

    // AI 카피 도우미
    public string $aiProduct = '';

    public string $aiTarget = '';

    public string $aiTone = 'friendly';

    /** @var list<string> */
    public array $aiSubjects = [];

    public string $aiBody = '';

    public bool $aiMock = false;

    public ?string $aiProvider = null;

    /** @var list<array{kind: string, id: int, title: string, open_rate: float|null}> 카피를 만들 때 참고한 과거 캠페인과 브랜드 문서 */
    public array $aiReferences = [];

    public ?string $aiError = null;

    public function mount(?Campaign $campaign = null): void
    {
        if ($campaign?->exists) {
            $this->authorize('update', $campaign);

            $this->campaignId = $campaign->id;
            $this->name = $campaign->name;
            $this->channel = $campaign->channel;
            $this->subject = $campaign->subject;
            $this->body = $campaign->body;
            $this->segment_id = $campaign->segment_id ?? '';
            $this->scheduled_at = $campaign->scheduled_at?->format('Y-m-d\TH:i') ?? '';

            return;
        }

        $this->authorize('create', Campaign::class);
    }

    public function saveDraft()
    {
        $this->validateContent();
        $campaign = $this->persist('draft', null);

        session()->flash('notice', '임시저장했습니다.');

        return $this->redirect('/campaigns/'.$campaign->id, navigate: true);
    }

    public function schedule()
    {
        $data = $this->validateContent();

        $this->validate(
            ['scheduled_at' => ['required', 'date', 'after:'.now()->addMinutes(1)->toDateTimeString()]],
            [
                'scheduled_at.required' => '예약 일시를 선택해 주세요.',
                'scheduled_at.date' => '예약 일시 형식이 올바르지 않습니다.',
                'scheduled_at.after' => '예약 일시는 현재 시각 이후여야 합니다.',
            ],
        );

        if ($this->recipientCount === 0) {
            $this->addError('segment_id', '발송 대상 고객이 없습니다. 세그먼트를 확인해 주세요.');

            return null;
        }

        $campaign = $this->persist('scheduled', Carbon::parse($this->scheduled_at));
        session()->flash('notice', $campaign->scheduled_at->format('m월 d일 H:i').'에 발송되도록 예약했습니다.');

        return $this->redirect('/campaigns/'.$campaign->id, navigate: true);
    }

    /** 발송 확인 창을 연다. 내용 검증과 대상 확인을 먼저 한다. */
    public function requestSend(): void
    {
        $this->validateContent();

        if ($this->recipientCount === 0) {
            $this->addError('segment_id', '발송 대상 고객이 없습니다. 세그먼트를 확인해 주세요.');

            return;
        }

        try {
            app(CampaignSender::class)->assertEmailSendingEnabled($this->draftCampaign());
            app(CampaignSender::class)->assertWithinEmailQuota($this->draftCampaign(), $this->recipientCount);
        } catch (DomainException $e) {
            $this->addError('segment_id', $e->getMessage());

            return;
        }

        $this->confirmingSend = true;
    }

    public function cancelSend(): void
    {
        $this->confirmingSend = false;
    }

    public function confirmSend(CampaignSender $sender)
    {
        $this->validateContent();
        $campaign = $this->persist('draft', null);
        $this->authorize('send', $campaign);

        try {
            $sender->start($campaign);
        } catch (DomainException $e) {
            $this->confirmingSend = false;
            $this->addError('segment_id', $e->getMessage());

            return null;
        }

        session()->flash('notice', '발송을 시작했습니다.');

        return $this->redirect('/campaigns/'.$campaign->id, navigate: true);
    }

    /** 제품/타겟 설명으로 제목 후보와 본문 초안을 생성한다. 결과는 사용자가 골라 적용할 때까지 폼에 반영되지 않는다. */
    public function generateCopy(AiClient $ai): void
    {
        $this->authorize('create', Campaign::class);
        $this->reset(['aiSubjects', 'aiBody', 'aiError', 'aiMock', 'aiProvider', 'aiReferences']);

        $this->validate([
            'aiProduct' => ['required', 'string', 'max:300'],
            'aiTarget' => ['nullable', 'string', 'max:200'],
            'aiTone' => ['required', Rule::in(['friendly', 'professional', 'playful', 'urgent'])],
        ], [
            'aiProduct.required' => '제품이나 서비스를 간단히 설명해 주세요.',
            'aiProduct.max' => '설명은 300자 이하로 입력해 주세요.',
            'aiTarget.max' => '타겟은 200자 이하로 입력해 주세요.',
        ]);

        // 일일 상한: 실제 모델 호출 횟수가 한도에 도달하면 더 이상 호출하지 않는다(비용 보호).
        if (app(AiUsage::class)->copyRemainingToday() === 0) {
            $this->aiError = '오늘 AI 카피 생성 한도를 모두 사용했습니다. 내일 다시 시도해 주세요.';

            return;
        }

        // AI 호출 비용을 보호하기 위해 사용자당 분당 10회로 제한한다.
        $key = 'ai-copy:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->aiError = RateLimiter::availableIn($key).'초 후에 다시 시도해 주세요.';

            return;
        }

        RateLimiter::hit($key, 60);

        $input = ['product' => trim($this->aiProduct), 'target' => trim($this->aiTarget), 'tone' => $this->aiTone, 'channel' => $this->channel];

        $usage = app(AiUsage::class);
        $examples = app(CopyExamples::class)->candidates();
        $brandChunks = app(BrandGuide::class)->candidates();

        try {
            $result = $ai->generateCopy(
                $input,
                allowOpenai: $usage->openaiRemainingToday() !== 0,
                examples: $examples,
                allowExternalEmbedding: $usage->embedRemainingToday() !== 0,
                brandChunks: $brandChunks,
            );
        } catch (AiUnavailableException $e) {
            $this->aiError = $e->getMessage();

            return;
        }

        // 쿼리 임베딩에 외부 공급자를 썼다면 임베딩 일일 사용량에 더한다.
        if (in_array($result['embedding_provider'] ?? null, ['gemini', 'openai'], true)) {
            $usage->recordEmbedding();
        }

        $provider = $result['provider'] ?? ($result['mock'] ? 'mock' : null);

        AiGeneration::create([
            'user_id' => auth()->id(),
            'type' => AiGeneration::TYPE_COPY,
            'input' => $input,
            'output' => $result,
            'provider' => $provider,
            'mock' => $result['mock'],
        ]);

        $this->aiSubjects = $result['subjects'];
        $this->aiBody = $result['body'];
        $this->aiMock = $result['mock'];
        $this->aiProvider = $provider;
        $this->aiReferences = $this->displayReferences($result['references'] ?? [], $examples, $brandChunks);
    }

    /**
     * 화면에 보여 줄 참고 자료. 우리가 보낸 후보에 있던 것만 남기고 제목과 오픈율은 우리 쪽 값을 쓴다.
     * AI 서비스 응답이 다른 id를 끼워 넣거나 제목을 바꿔도 화면과 링크에 나오지 않게 하기 위해서다.
     *
     * @param  list<array<string, mixed>>  $references
     * @param  list<array<string, mixed>>  $examples
     * @param  list<array<string, mixed>>  $brandChunks
     * @return list<array{kind: string, id: int, title: string, open_rate: float|null}>
     */
    private function displayReferences(array $references, array $examples, array $brandChunks): array
    {
        $campaigns = array_column($examples, null, 'id');
        $brand = array_column($brandChunks, null, 'id');
        $shown = [];

        foreach ($references as $reference) {
            $id = $reference['id'] ?? null;

            if (! is_int($id)) {
                continue;
            }

            if (($reference['kind'] ?? null) === 'campaign' && isset($campaigns[$id])) {
                $shown[] = ['kind' => 'campaign', 'id' => $id, 'title' => (string) $campaigns[$id]['subject'], 'open_rate' => (float) $campaigns[$id]['open_rate']];
            } elseif (($reference['kind'] ?? null) === 'brand' && isset($brand[$id])) {
                $shown[] = ['kind' => 'brand', 'id' => $id, 'title' => (string) $brand[$id]['document_title'], 'open_rate' => null];
            }
        }

        return $shown;
    }

    public function useSubject(int $index): void
    {
        if (isset($this->aiSubjects[$index])) {
            $this->subject = $this->aiSubjects[$index];
        }
    }

    public function useBody(): void
    {
        if ($this->aiBody !== '') {
            $this->body = $this->aiBody;
        }
    }

    #[Computed]
    public function segments()
    {
        return Segment::query()->orderBy('name')->get(['id', 'name']);
    }

    /** 현재 선택한 세그먼트 기준 발송 대상 수 */
    #[Computed]
    public function recipientCount(): int
    {
        return app(CampaignSender::class)->recipientCount($this->draftCampaign());
    }

    /** 이 워크스페이스에서 이메일을 실제로 발송할 수 있는지(체험/데모 워크스페이스는 false) */
    #[Computed]
    public function emailSendingEnabled(): bool
    {
        return (bool) Workspace::query()->whereKey(auth()->user()->currentWorkspaceId())->value('sending_enabled');
    }

    /** 오늘 남은 AI 카피 생성 횟수. 한도가 설정되지 않았으면 null */
    #[Computed]
    public function aiRemaining(): ?int
    {
        return app(AiUsage::class)->copyRemainingToday();
    }

    /** 오늘 남은 이메일 발송 한도. 한도가 설정되지 않았으면 null */
    #[Computed]
    public function emailQuota(): ?int
    {
        return app(CampaignSender::class)->remainingEmailQuota();
    }

    /** 첫 번째 고객으로 변수를 치환한 미리보기 */
    #[Computed]
    public function preview(): array
    {
        $renderer = app(MessageRenderer::class);
        $sample = Contact::query()->orderBy('id')->first() ?? new Contact(['name' => '홍길동', 'email' => 'sample@example.com']);

        return [
            'subject' => $renderer->subject($this->subject, $sample),
            'html' => $renderer->html($this->body, $sample),
            'name' => $sample->name,
        ];
    }

    public function render()
    {
        return view('livewire.campaigns.form');
    }

    /** @return array<string, mixed> */
    private function validateContent(): array
    {
        $workspaceId = auth()->user()->currentWorkspaceId();

        return $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'channel' => ['required', Rule::in(array_keys(Campaign::CHANNELS))],
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'segment_id' => ['nullable', Rule::exists('segments', 'id')->where('workspace_id', $workspaceId)],
        ], [
            'name.required' => '캠페인 이름을 입력해 주세요.',
            'name.max' => '이름은 100자 이하로 입력해 주세요.',
            'channel.in' => '지원하지 않는 채널입니다.',
            'subject.required' => '제목을 입력해 주세요.',
            'subject.max' => '제목은 150자 이하로 입력해 주세요.',
            'body.required' => '본문을 입력해 주세요.',
            'body.max' => '본문은 5,000자 이하로 입력해 주세요.',
            'segment_id.exists' => '선택한 세그먼트를 찾을 수 없습니다.',
        ]);
    }

    private function persist(string $status, ?Carbon $scheduledAt): Campaign
    {
        $payload = [
            'name' => trim($this->name),
            'channel' => $this->channel,
            'subject' => trim($this->subject),
            'body' => $this->body,
            'segment_id' => $this->segment_id === '' ? null : (int) $this->segment_id,
            'status' => $status,
            'scheduled_at' => $scheduledAt,
        ];

        if ($this->campaignId) {
            $campaign = Campaign::findOrFail($this->campaignId);
            $this->authorize('update', $campaign);
            $campaign->update($payload);

            return $campaign;
        }

        $this->authorize('create', Campaign::class);
        $campaign = Campaign::create($payload);
        $this->campaignId = $campaign->id;

        return $campaign;
    }

    private function draftCampaign(): Campaign
    {
        return new Campaign([
            'workspace_id' => auth()->user()->currentWorkspaceId(),
            'segment_id' => $this->segment_id === '' ? null : (int) $this->segment_id,
            'channel' => $this->channel,
        ]);
    }
}
