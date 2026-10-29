<?php

use App\Jobs\EmbedCampaign;
use App\Livewire\Campaigns\Form;
use App\Models\AiGeneration;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiUsage;
use App\Services\Ai\CopyExamples;
use App\Services\Campaigns\CampaignSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/** 발송 완료 캠페인을 만든다. $sent명에게 보냈고 그중 $opened명이 열었다. */
function ragCampaign(int $workspaceId, array $attrs = [], int $sent = 30, int $opened = 0, ?array $embedding = [0.5, 0.5, 0.5, 0.5], string $provider = 'hash'): Campaign
{
    static $n = 0;

    $campaign = Campaign::withoutGlobalScopes()->create($attrs + [
        'workspace_id' => $workspaceId, 'name' => '캠페인', 'channel' => 'email', 'subject' => '가을 신상 코트', 'body' => '가을 코트 소식',
        'status' => 'sent', 'sent_at' => now(),
    ]);

    if ($embedding !== null) {
        $campaign->forceFill(['embedding' => $embedding, 'embedding_provider' => $provider])->save();
    }

    for ($i = 0; $i < $sent; $i++) {
        $n++;
        $contact = Contact::withoutGlobalScopes()->create(['workspace_id' => $workspaceId, 'email' => "rag{$n}@example.test", 'name' => "수신자{$n}"]);
        CampaignMessage::create([
            'campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'status' => 'sent', 'opened_at' => $i < $opened ? now() : null,
        ]);
    }

    return $campaign;
}

beforeEach(function () {
    [$this->user, $this->workspace] = userInWorkspace();
    config(['services.ai.url' => 'http://ai.test', 'services.ai.token' => 'secret-token']);
    Cache::flush();
});

// ---- 후보 선정 --------------------------------------------------------------

it('수신자 30명 이상이고 오픈율이 중앙값 이상인 같은 워크스페이스 캠페인만 후보로 보낸다', function () {
    $this->actingAs($this->user);
    [, $other] = userInWorkspace('admin', '다른 스토어');

    $high = ragCampaign($this->workspace->id, ['subject' => '높은 반응'], sent: 30, opened: 25);
    $mid = ragCampaign($this->workspace->id, ['subject' => '중간 반응'], sent: 30, opened: 20);
    ragCampaign($this->workspace->id, ['subject' => '낮은 반응'], sent: 30, opened: 10);
    ragCampaign($this->workspace->id, ['subject' => '수신자 부족'], sent: 10, opened: 10);
    ragCampaign($this->workspace->id, ['subject' => '임베딩 없음'], sent: 30, opened: 30, embedding: null);
    ragCampaign($this->workspace->id, ['subject' => '초안', 'status' => 'draft'], sent: 30, opened: 30);
    ragCampaign($other->id, ['subject' => '다른 워크스페이스'], sent: 30, opened: 30);

    $candidates = app(CopyExamples::class)->candidates();

    expect(collect($candidates)->pluck('id')->sort()->values()->all())->toBe(collect([$high->id, $mid->id])->sort()->values()->all())
        ->and(collect($candidates)->pluck('subject')->contains('다른 워크스페이스'))->toBeFalse();
});

it('후보에 벡터, 공급자, 오픈율, 클릭률을 담고 클릭률은 1을 넘지 않는다', function () {
    $this->actingAs($this->user);
    $campaign = ragCampaign($this->workspace->id, sent: 30, opened: 15, embedding: [0.1, 0.2], provider: 'gemini');

    foreach (range(1, 45) as $_) {
        CampaignEvent::create(['campaign_id' => $campaign->id, 'contact_id' => CampaignMessage::first()->contact_id, 'type' => 'click', 'created_at' => now()]);
    }

    expect(app(CopyExamples::class)->candidates())->toBe([[
        'id' => $campaign->id, 'subject' => '가을 신상 코트', 'body' => '가을 코트 소식',
        'open_rate' => 0.5, 'click_rate' => 1.0, 'vector' => [0.1, 0.2], 'embedding_provider' => 'gemini',
    ]]);
});

it('후보가 없으면 빈 목록이다', function () {
    $this->actingAs($this->user);

    expect(app(CopyExamples::class)->candidates())->toBe([]);
});

it('벡터는 모델 직렬화에 실리지 않는다', function () {
    $campaign = ragCampaign($this->workspace->id, sent: 0);

    expect($campaign->toArray())->not->toHaveKey('embedding')
        ->and($campaign->embedding)->toBe([0.5, 0.5, 0.5, 0.5]);
});

// ---- 임베딩 작업 ------------------------------------------------------------

it('발송이 끝나면 임베딩 작업을 등록한다', function () {
    Queue::fake();
    $campaign = ragCampaign($this->workspace->id, ['status' => 'sending'], sent: 2, embedding: null);

    app(CampaignSender::class)->finalize($campaign->id);

    Queue::assertPushed(EmbedCampaign::class, fn ($job) => $job->campaignId === $campaign->id);
});

it('전부 실패한 캠페인은 임베딩하지 않는다', function () {
    Queue::fake();
    $campaign = ragCampaign($this->workspace->id, ['status' => 'sending'], sent: 0, embedding: null);
    $contact = Contact::withoutGlobalScopes()->create(['workspace_id' => $this->workspace->id, 'email' => 'fail@example.test', 'name' => 'x']);
    CampaignMessage::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'status' => 'failed']);

    app(CampaignSender::class)->finalize($campaign->id);

    Queue::assertNotPushed(EmbedCampaign::class);
});

it('제목과 본문을 임베딩해 공급자와 함께 저장한다', function () {
    Http::fake(['ai.test/embed' => Http::response(['vectors' => [[0.123456789, 0.2]], 'provider' => 'gemini', 'dimensions' => 256])]);
    $campaign = ragCampaign($this->workspace->id, ['subject' => '가을 코트', 'body' => '소식입니다'], sent: 30, embedding: null);

    (new EmbedCampaign($campaign->id))->handle(app(AiClient::class));

    $fresh = Campaign::withoutGlobalScopes()->find($campaign->id);
    expect($fresh->embedding)->toBe([0.12346, 0.2])->and($fresh->embedding_provider)->toBe('gemini');
    Http::assertSent(fn ($request) => $request['texts'] === ["가을 코트\n소식입니다"]);
    expect(app(AiUsage::class)->embedRemainingToday())->toBeNull();
});

it('수신자가 30명 미만이거나 발송 완료가 아니면 AI 서비스를 호출하지 않는다', function () {
    Http::fake();
    $few = ragCampaign($this->workspace->id, sent: 5, embedding: null);
    $draft = ragCampaign($this->workspace->id, ['status' => 'draft'], sent: 30, embedding: null);

    (new EmbedCampaign($few->id))->handle(app(AiClient::class));
    (new EmbedCampaign($draft->id))->handle(app(AiClient::class));
    (new EmbedCampaign(999999))->handle(app(AiClient::class));

    Http::assertNothingSent();
    expect(Campaign::withoutGlobalScopes()->find($few->id)->embedding)->toBeNull();
});

it('임베딩 재생성 명령은 임베딩이 없는 발송 완료 캠페인만 등록하고 --all이면 전부 등록한다', function () {
    Queue::fake();
    $missing = ragCampaign($this->workspace->id, sent: 0, embedding: null);
    $done = ragCampaign($this->workspace->id, sent: 0);
    ragCampaign($this->workspace->id, ['status' => 'draft'], sent: 0, embedding: null);

    $this->artisan('mktly:embeddings:rebuild')->expectsOutputToContain('1건')->assertSuccessful();
    Queue::assertPushed(EmbedCampaign::class, 1);
    Queue::assertPushed(EmbedCampaign::class, fn ($job) => $job->campaignId === $missing->id);

    $this->artisan('mktly:embeddings:rebuild', ['--all' => true])->expectsOutputToContain('2건')->assertSuccessful();
    Queue::assertPushed(EmbedCampaign::class, fn ($job) => $job->campaignId === $done->id);
});

// ---- 카피 패널 --------------------------------------------------------------

it('카피 생성 요청에 후보를 담고 참고한 자료를 보여 준다', function () {
    $campaign = ragCampaign($this->workspace->id, ['subject' => '가을 신상 코트 단독'], sent: 30, opened: 15, embedding: [0.1, 0.2], provider: 'gemini');
    Http::fake(['*/copy/generate' => Http::response([
        'subjects' => ['a', 'b'], 'body' => '본문', 'mock' => false, 'provider' => 'gemini', 'embedding_provider' => 'hash',
        'references' => [
            ['kind' => 'campaign', 'id' => $campaign->id, 'title' => '가짜 제목', 'open_rate' => 0.99, 'score' => 0.8],
            ['kind' => 'campaign', 'id' => 999999, 'title' => '끼워 넣은 캠페인', 'open_rate' => 0.9, 'score' => 0.7],
        ],
    ])]);

    Livewire::actingAs($this->user)->test(Form::class)
        ->set('aiProduct', '니트')->call('generateCopy')
        ->assertSet('aiReferences', [['kind' => 'campaign', 'id' => $campaign->id, 'title' => '가을 신상 코트 단독', 'open_rate' => 0.5]])
        ->assertSee('참고한 자료')->assertSee('가을 신상 코트 단독')->assertSee('오픈율 50.0%')
        ->assertDontSee('끼워 넣은 캠페인')->assertDontSee('가짜 제목');

    Http::assertSent(fn ($request) => count($request['examples']) === 1
        && $request['examples'][0]['id'] === $campaign->id
        && $request['examples'][0]['vector'] === [0.1, 0.2]
        && $request['allow_external_embedding'] === true);

    // 참고한 자료는 생성 기록에도 남는다. 벡터는 기록하지 않는다.
    $record = AiGeneration::withoutGlobalScopes()->latest('id')->first();
    expect($record->output['references'][0]['id'])->toBe($campaign->id)->and($record->input)->not->toHaveKey('examples');
});

it('참고할 캠페인이 없으면 참고한 자료를 보여 주지 않는다', function () {
    Http::fake(['*/copy/generate' => Http::response(['subjects' => ['a'], 'body' => '본문', 'mock' => true, 'provider' => 'mock', 'references' => []])]);

    Livewire::actingAs($this->user)->test(Form::class)
        ->set('aiProduct', '니트')->call('generateCopy')
        ->assertSet('aiReferences', [])->assertDontSee('참고한 자료');

    Http::assertSent(fn ($request) => $request['examples'] === []);
});

it('쿼리 임베딩에 외부 공급자를 썼을 때만 임베딩 사용량을 올린다', function () {
    config(['mktly.ai.embed_daily_limit' => 5]);
    Http::fake(['*/copy/generate' => Http::sequence()
        ->push(['subjects' => ['a'], 'body' => 'b', 'mock' => false, 'provider' => 'gemini', 'embedding_provider' => 'gemini'])
        ->push(['subjects' => ['a'], 'body' => 'b', 'mock' => false, 'provider' => 'gemini', 'embedding_provider' => 'hash'])
        ->push(['subjects' => ['a'], 'body' => 'b', 'mock' => false, 'provider' => 'gemini'])]);

    $component = Livewire::actingAs($this->user)->test(Form::class)->set('aiProduct', '니트');
    $component->call('generateCopy');
    expect(app(AiUsage::class)->embedRemainingToday())->toBe(4);

    $component->call('generateCopy')->call('generateCopy');
    expect(app(AiUsage::class)->embedRemainingToday())->toBe(4);
});

it('임베딩 상한에 도달하면 카피 요청에 외부 임베딩을 쓰지 말라고 알린다', function () {
    config(['mktly.ai.embed_daily_limit' => 1]);
    app(AiUsage::class)->recordEmbedding();
    Http::fake(['*/copy/generate' => Http::response(['subjects' => ['a'], 'body' => 'b', 'mock' => true, 'provider' => 'mock'])]);

    Livewire::actingAs($this->user)->test(Form::class)->set('aiProduct', '니트')->call('generateCopy');

    Http::assertSent(fn ($request) => $request['allow_external_embedding'] === false);
});
