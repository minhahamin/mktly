<?php

use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.ai.url' => 'http://ai.test', 'services.ai.token' => 'secret-token']);
});

it('카피 생성 요청에 내부 토큰을 붙이고 결과를 돌려준다', function () {
    Http::fake(['ai.test/copy/generate' => Http::response(['subjects' => ['a', 'b'], 'body' => '본문', 'mock' => true])]);

    $result = app(AiClient::class)->generateCopy(['product' => '코트']);

    expect($result)->toMatchArray(['subjects' => ['a', 'b'], 'body' => '본문', 'mock' => true]);
    Http::assertSent(fn (Request $r) => $r->url() === 'http://ai.test/copy/generate'
        && $r->hasHeader('X-Internal-Token', 'secret-token')
        && $r['product'] === '코트');
});

it('이탈 예측과 클러스터링 엔드포인트를 호출한다', function () {
    Http::fake([
        'ai.test/predict/churn' => Http::response(['scores' => [['id' => 1, 'score' => 0.5]], 'metrics' => ['method' => 'heuristic']]),
        'ai.test/cluster' => Http::response(['assignments' => [['id' => 1, 'cluster' => 1]], 'clusters' => []]),
    ]);

    $ai = app(AiClient::class);

    expect($ai->scoreChurn([['id' => 1]], 60)['scores'][0]['score'])->toBe(0.5);
    expect($ai->cluster([['id' => 1]], 3)['assignments'][0]['cluster'])->toBe(1);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/predict/churn') && $r['lapse_days'] === 60);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/cluster') && $r['k'] === 3);
});

it('연결 실패는 사용자용 메시지의 예외로 바꾼다', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused to ai.test:8001'));

    expect(fn () => app(AiClient::class)->generateCopy(['product' => 'x']))
        ->toThrow(AiUnavailableException::class, 'AI 서비스에 연결할 수 없습니다');
});

it('서비스가 알려 준 한국어 오류(422, 502)는 그대로 전달한다', function () {
    Http::fake(['ai.test/cluster' => Http::response(['detail' => '클러스터링하려면 고객이 최소 6명 필요합니다.'], 422)]);

    expect(fn () => app(AiClient::class)->cluster([['id' => 1]], 3))
        ->toThrow(AiUnavailableException::class, '최소 6명');
});

it('그 밖의 오류는 내부 정보를 숨긴 일반 문구로 바꾼다', function () {
    Http::fake(['ai.test/*' => Http::response(['detail' => 'Traceback secret internals'], 500)]);

    try {
        app(AiClient::class)->generateCopy(['product' => 'x']);
        $this->fail('예외가 발생해야 합니다.');
    } catch (AiUnavailableException $e) {
        expect($e->getMessage())->toContain('처리하지 못했습니다')->not->toContain('Traceback');
    }
});

it('인증 실패(401)도 일반 문구로 처리한다', function () {
    Http::fake(['ai.test/*' => Http::response(['detail' => 'invalid internal token'], 401)]);

    try {
        app(AiClient::class)->generateCopy(['product' => 'x']);
        $this->fail('예외가 발생해야 합니다.');
    } catch (AiUnavailableException $e) {
        expect($e->getMessage())->not->toContain('token');
    }
});
