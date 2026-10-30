<?php

namespace App\Livewire\Images;

use App\Models\GeneratedImage;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\AiUsage;
use App\Services\Images\CardNews;
use DomainException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('카드뉴스 만들기')]
class Create extends Component
{
    public string $topic = '';

    public string $target = '';

    public string $tone = 'friendly';

    public string $kind = 'cardnews';

    public int $slideCount = 5;

    public string $ratio = '1:1';

    /** @var list<array{title: string, body: string}> 초안으로 만든 뒤 사용자가 고친 슬라이드 문구 */
    public array $slides = [];

    public ?int $generationId = null;

    public bool $aiMock = false;

    public ?string $aiProvider = null;

    /** @var list<array{kind: string, id: int, title: string, open_rate: float|null}> */
    public array $aiReferences = [];

    /** AI 배경 삽화를 쓸지. 유료 호출이라 기본은 꺼져 있다. */
    public bool $aiBackground = false;

    public ?string $error = null;

    public function mount(): void
    {
        $this->authorize('create', GeneratedImage::class);
    }

    /** 종류나 장수를 바꾸면 이전 초안은 맞지 않으므로 지운다. 대표 이미지는 가로형을 기본으로 한다. */
    public function updated(string $name): void
    {
        if ($name === 'kind') {
            $this->ratio = $this->kind === 'cover' ? '16:9' : ($this->ratio === '16:9' ? '1:1' : $this->ratio);
        }

        if (in_array($name, ['kind', 'slideCount'], true)) {
            $this->reset(['slides', 'generationId', 'aiReferences', 'aiMock', 'aiProvider']);
        }
    }

    public function draft(CardNews $cardNews): void
    {
        $this->authorize('create', GeneratedImage::class);
        $this->reset(['slides', 'generationId', 'aiReferences', 'aiMock', 'aiProvider', 'error']);

        $this->validate([
            'topic' => ['required', 'string', 'max:300'],
            'target' => ['nullable', 'string', 'max:200'],
            'tone' => ['required', Rule::in(['friendly', 'professional', 'playful', 'urgent'])],
            'kind' => ['required', Rule::in(array_keys(GeneratedImage::KINDS))],
            'slideCount' => ['required', 'integer', 'between:3,6'],
            'ratio' => ['required', Rule::in(array_keys(GeneratedImage::RATIOS))],
        ], [
            'topic.required' => '주제나 제품을 간단히 설명해 주세요.',
            'topic.max' => '주제는 300자 이하로 입력해 주세요.',
            'target.max' => '타겟은 200자 이하로 입력해 주세요.',
        ]);

        if ($this->throttled()) {
            return;
        }

        try {
            $result = $cardNews->draft([
                'topic' => trim($this->topic),
                'target' => trim($this->target),
                'tone' => $this->tone,
                'kind' => $this->kind,
                'slide_count' => $this->slideCount,
            ], auth()->id());
        } catch (DomainException|AiUnavailableException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->slides = $result['slides'];
        $this->generationId = $result['generation_id'];
        $this->aiMock = $result['mock'];
        $this->aiProvider = $result['provider'];
        $this->aiReferences = $result['references'];
    }

    /** 사용자가 고친 문구로 이미지를 그려 저장하고 목록으로 이동한다. */
    public function generate(CardNews $cardNews)
    {
        $this->authorize('create', GeneratedImage::class);
        $this->error = null;

        $this->validate([
            'kind' => ['required', Rule::in(array_keys(GeneratedImage::KINDS))],
            'ratio' => ['required', Rule::in(array_keys(GeneratedImage::RATIOS))],
            'slides' => ['required', 'array', $this->kind === 'cover' ? 'size:1' : 'between:3,6'],
            'slides.*.title' => ['required', 'string', 'max:20'],
            'slides.*.body' => ['nullable', 'string', 'max:60'],
        ], [
            'slides.required' => '먼저 문구 초안을 만들어 주세요.',
            'slides.size' => '대표 이미지는 한 장만 만들 수 있습니다.',
            'slides.between' => '카드뉴스는 3장에서 6장까지 만들 수 있습니다.',
            'slides.*.title.required' => '제목을 입력해 주세요.',
            'slides.*.title.max' => '제목은 20자 이하로 입력해 주세요.',
            'slides.*.body.max' => '본문은 60자 이하로 입력해 주세요.',
        ]);

        if ($this->throttled()) {
            return null;
        }

        try {
            $cardNews->render(
                array_map(fn ($slide) => ['title' => trim($slide['title']), 'body' => trim((string) ($slide['body'] ?? ''))], array_values($this->slides)),
                $this->kind,
                $this->ratio,
                trim($this->topic) ?: trim($this->slides[0]['title']),
                $this->generationId,
                auth()->id(),
                $this->aiBackground,
            );
        } catch (DomainException|AiUnavailableException $e) {
            $this->error = $e->getMessage();

            return null;
        }

        session()->flash('notice', '이미지를 만들었습니다.');

        return $this->redirect('/images', navigate: true);
    }

    public function render()
    {
        return view('livewire.images.create');
    }

    /** 오늘 남은 AI 생성 횟수(카피와 합산). 한도가 설정되지 않았으면 null */
    #[Computed]
    public function aiRemaining(): ?int
    {
        return app(AiUsage::class)->copyRemainingToday();
    }

    /** 오늘 남은 AI 배경 삽화 세트 수. 운영자가 상한을 설정하지 않았으면 기능이 꺼져 있어 null이다. */
    #[Computed]
    public function imageRemaining(): ?int
    {
        return config('mktly.ai.image_daily_limit') ? app(AiUsage::class)->imageRemainingToday() : null;
    }

    /** 분당 10회로 제한한다(초안은 LLM 비용, 렌더는 CPU 비용을 보호한다). 제한에 걸리면 안내 문구를 넣고 true를 돌려준다. */
    private function throttled(): bool
    {
        $key = 'ai-image:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->error = RateLimiter::availableIn($key).'초 후에 다시 시도해 주세요.';

            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }
}
