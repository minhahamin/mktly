<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** 내부 AI 서비스(FastAPI) 호출은 모두 이 클래스를 거친다. */
class AiClient
{
    /**
     * 마케팅 카피(제목 후보 5개와 본문 초안)를 생성한다.
     *
     * @param  array{product: string, target?: string, tone?: string, channel?: string}  $payload
     * @param  bool  $allowOpenai  false이면 AI 서비스가 유료 OpenAI 호출을 건너뛴다(일일 상한 도달)
     * @return array{subjects: list<string>, body: string, mock: bool, provider?: string}
     */
    public function generateCopy(array $payload, bool $allowOpenai = true): array
    {
        return $this->post('/copy/generate', $payload + ['allow_openai' => $allowOpenai]);
    }

    /**
     * 고객별 이탈 확률을 계산한다.
     *
     * @param  list<array<string, mixed>>  $contacts  ContactFeatureBuilder가 만든 지표 행
     * @return array{scores: list<array{id: int, score: float}>, metrics: array<string, mixed>}
     */
    public function scoreChurn(array $contacts, int $lapseDays = 90): array
    {
        return $this->post('/predict/churn', ['contacts' => $contacts, 'lapse_days' => $lapseDays]);
    }

    /**
     * 고객을 k개 그룹으로 나눈다.
     *
     * @param  list<array<string, mixed>>  $contacts
     * @return array{assignments: list<array{id: int, cluster: int}>, clusters: list<array<string, mixed>>}
     */
    public function cluster(array $contacts, int $k): array
    {
        return $this->post('/cluster', ['contacts' => $contacts, 'k' => $k]);
    }

    /**
     * AI 서비스 연결 상태를 점검한다.
     *
     * @return 'ok'|'unauthorized'|'unreachable' unauthorized는 서비스는 응답하지만 내부 토큰이 맞지 않는 경우
     */
    public function status(): string
    {
        try {
            $http = Http::baseUrl(config('services.ai.url'))->timeout(10)->acceptJson();

            if (! $http->get('/health')->successful()) {
                return 'unreachable';
            }

            $ping = $http->withHeaders(['X-Internal-Token' => (string) config('services.ai.token')])->get('/ping');
        } catch (ConnectionException) {
            return 'unreachable';
        }

        return $ping->successful() ? 'ok' : 'unauthorized';
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $payload): array
    {
        try {
            $response = Http::baseUrl(config('services.ai.url'))
                ->withHeaders(['X-Internal-Token' => (string) config('services.ai.token')])
                ->timeout(config('services.ai.timeout'))
                ->acceptJson()
                ->post($path, $payload);
        } catch (ConnectionException) {
            throw new AiUnavailableException('AI 서비스에 연결할 수 없습니다. 잠시 후 다시 시도해 주세요.');
        }

        if ($response->failed()) {
            throw new AiUnavailableException($this->messageFor($response));
        }

        return $response->json();
    }

    /** 서비스가 알려 준 한국어 오류(422, 502)는 그대로, 나머지는 일반 문구로 바꾼다. */
    private function messageFor(Response $response): string
    {
        $detail = $response->json('detail');

        if (in_array($response->status(), [422, 502], true) && is_string($detail)) {
            return $detail;
        }

        return 'AI 서비스가 요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.';
    }
}
