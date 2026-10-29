<?php

use App\Models\AiGeneration;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiUsage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.ai.url' => 'http://ai.test', 'services.ai.token' => 'secret-token']);
    Cache::flush();
});

it('임베딩 상한을 설정하지 않으면 제한하지 않는다', function () {
    config(['mktly.ai.embed_daily_limit' => null]);

    expect(app(AiUsage::class)->embedRemainingToday())->toBeNull();
});

it('기록한 외부 임베딩 호출만큼 남은 횟수가 줄고 0 아래로 내려가지 않는다', function () {
    config(['mktly.ai.embed_daily_limit' => 2]);
    $usage = app(AiUsage::class);

    expect($usage->embedRemainingToday())->toBe(2);

    $usage->recordEmbedding();
    expect($usage->embedRemainingToday())->toBe(1);

    $usage->recordEmbedding();
    $usage->recordEmbedding();
    expect($usage->embedRemainingToday())->toBe(0);
});

it('사용량은 날짜가 바뀌면 다시 센다', function () {
    config(['mktly.ai.embed_daily_limit' => 1]);
    $usage = app(AiUsage::class);
    $usage->recordEmbedding();

    $this->travel(1)->day();

    expect($usage->embedRemainingToday())->toBe(1);
});

it('임베딩 요청에 내부 토큰과 외부 호출 허용 여부를 붙인다', function () {
    config(['mktly.ai.embed_daily_limit' => 3, 'mktly.ai.openai_daily_limit' => null]);
    Http::fake(['ai.test/embed' => Http::response(['vectors' => [[0.1]], 'provider' => 'gemini', 'dimensions' => 256])]);

    $result = app(AiClient::class)->embed(['가을 코트']);

    expect($result['provider'])->toBe('gemini');
    Http::assertSent(fn (Request $r) => $r->url() === 'http://ai.test/embed'
        && $r->hasHeader('X-Internal-Token', 'secret-token')
        && $r['texts'] === ['가을 코트']
        && $r['allow_external'] === true
        && $r['allow_openai'] === true);
});

it('외부 공급자가 응답하면 사용량을 올리고 해시 벡터는 올리지 않는다', function () {
    config(['mktly.ai.embed_daily_limit' => 5]);
    Http::fake(['ai.test/embed' => Http::sequence()
        ->push(['vectors' => [[0.1]], 'provider' => 'gemini', 'dimensions' => 256])
        ->push(['vectors' => [[0.1]], 'provider' => 'hash', 'dimensions' => 256])]);

    app(AiClient::class)->embed(['a']);
    app(AiClient::class)->embed(['b']);

    expect(app(AiUsage::class)->embedRemainingToday())->toBe(4);
});

it('상한에 도달하면 AI 서비스에 외부 호출을 하지 말라고 알린다', function () {
    config(['mktly.ai.embed_daily_limit' => 1]);
    app(AiUsage::class)->recordEmbedding();
    Http::fake(['ai.test/embed' => Http::response(['vectors' => [[0.1]], 'provider' => 'hash', 'dimensions' => 256])]);

    app(AiClient::class)->embed(['a']);

    Http::assertSent(fn (Request $r) => $r['allow_external'] === false);
    expect(app(AiUsage::class)->embedRemainingToday())->toBe(0);
});

it('OpenAI 상한에 도달하면 임베딩에서도 OpenAI를 건너뛰라고 알린다', function () {
    config(['mktly.ai.embed_daily_limit' => null, 'mktly.ai.openai_daily_limit' => 1]);
    Http::fake(['ai.test/embed' => Http::response(['vectors' => [[0.1]], 'provider' => 'hash', 'dimensions' => 256])]);
    AiGeneration::withoutGlobalScopes()->create([
        'workspace_id' => userInWorkspace()[1]->id, 'type' => 'copy', 'input' => [], 'output' => [], 'mock' => false, 'provider' => 'openai',
    ]);

    app(AiClient::class)->embed(['a']);

    Http::assertSent(fn (Request $r) => $r['allow_openai'] === false && $r['allow_external'] === true);
});
