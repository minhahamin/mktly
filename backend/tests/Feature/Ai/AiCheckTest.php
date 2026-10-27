<?php

use App\Services\Ai\AiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.ai.url' => 'http://ai.test', 'services.ai.token' => 'good-token']);
});

it('서비스가 응답하고 토큰이 맞으면 ok다', function () {
    Http::fake(['ai.test/health' => Http::response(['status' => 'ok']), 'ai.test/ping' => Http::response(['pong' => true])]);

    expect(app(AiClient::class)->status())->toBe('ok');
    $this->artisan('mktly:ai-check')->expectsOutputToContain('연결 정상')->assertSuccessful();
});

it('서비스는 응답하지만 토큰이 틀리면 unauthorized다', function () {
    Http::fake(['ai.test/health' => Http::response(['status' => 'ok']), 'ai.test/ping' => Http::response(['detail' => 'invalid internal token'], 401)]);

    expect(app(AiClient::class)->status())->toBe('unauthorized');
    $this->artisan('mktly:ai-check')->expectsOutputToContain('AI_INTERNAL_TOKEN')->assertFailed();
});

it('연결할 수 없으면 unreachable이고 토큰 값은 출력하지 않는다', function () {
    Http::fake(fn () => throw new ConnectionException('refused'));

    expect(app(AiClient::class)->status())->toBe('unreachable');
    $this->artisan('mktly:ai-check')->expectsOutputToContain('연결할 수 없습니다')->doesntExpectOutputToContain('good-token')->assertFailed();
});

it('헬스체크가 실패하면 unreachable이다', function () {
    Http::fake(['ai.test/health' => Http::response('', 503)]);

    expect(app(AiClient::class)->status())->toBe('unreachable');
});

it('내부 토큰은 /ping 요청에만 붙인다', function () {
    Http::fake(['ai.test/health' => Http::response(['status' => 'ok']), 'ai.test/ping' => Http::response(['pong' => true])]);

    app(AiClient::class)->status();

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/ping') && $r->hasHeader('X-Internal-Token', 'good-token'));
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/health') && ! $r->hasHeader('X-Internal-Token'));
});
