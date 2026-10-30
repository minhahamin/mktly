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
     * @param  list<array<string, mixed>>  $examples  참고 후보(CopyExamples::candidates). 비어 있으면 참고 없이 생성한다
     * @param  bool  $allowExternalEmbedding  false이면 참고 자료 검색에 외부 임베딩 호출을 쓰지 않는다(임베딩 일일 상한 도달)
     * @param  list<array<string, mixed>>  $brandChunks  브랜드 문서 조각 후보(BrandGuide::candidates). 비어 있으면 브랜드 지침 없이 생성한다
     * @return array{subjects: list<string>, body: string, mock: bool, provider?: string, references?: list<array<string, mixed>>, embedding_provider?: string|null}
     */
    public function generateCopy(array $payload, bool $allowOpenai = true, array $examples = [], bool $allowExternalEmbedding = true, array $brandChunks = []): array
    {
        return $this->recordEmbeddingUsage($this->post('/copy/generate', $payload + [
            'allow_openai' => $allowOpenai,
            'examples' => $examples,
            'brand_chunks' => $brandChunks,
            'allow_external_embedding' => $allowExternalEmbedding,
        ]));
    }

    /**
     * 카드뉴스/대표 이미지의 슬라이드 문구 초안을 만든다. 참고 자료 인자는 generateCopy와 같다.
     *
     * @param  array{topic: string, target?: string, tone?: string, kind?: string, slide_count?: int}  $payload
     * @param  list<array<string, mixed>>  $examples
     * @param  list<array<string, mixed>>  $brandChunks
     * @return array{slides: list<array{title: string, body: string}>, mock: bool, provider: string, references?: list<array<string, mixed>>, embedding_provider?: string|null}
     */
    public function cardnewsDraft(array $payload, bool $allowOpenai = true, array $examples = [], bool $allowExternalEmbedding = true, array $brandChunks = []): array
    {
        return $this->recordEmbeddingUsage($this->post('/cardnews/draft', $payload + [
            'allow_openai' => $allowOpenai,
            'examples' => $examples,
            'brand_chunks' => $brandChunks,
            'allow_external_embedding' => $allowExternalEmbedding,
        ]));
    }

    /**
     * 슬라이드 문구를 PNG 이미지로 그린다. AI 배경 삽화를 기다릴 수 있어 타임아웃이 더 길다.
     *
     * @param  list<array{title: string, body: string}>  $slides
     * @param  bool  $aiBackground  true이면 Gemini 이미지 모델로 배경 삽화를 시도한다(유료). 실패하면 템플릿 배경으로 그린다
     * @return array{images: list<array{index: int, width: int, height: int, png_base64: string}>, background_provider: 'gemini'|'template'}
     */
    public function cardnewsRender(array $slides, string $kind, string $ratio, string $seed, string $topic = '', bool $aiBackground = false): array
    {
        return $this->post('/cardnews/render', [
            'slides' => $slides,
            'kind' => $kind,
            'ratio' => $ratio,
            'seed' => $seed,
            'topic' => $topic,
            'ai_background' => $aiBackground,
        ], timeout: config('services.ai.image_timeout'));
    }

    /**
     * 텍스트를 256차원 벡터로 바꾼다. 일일 상한에 도달했으면 외부 호출 없이 해시 벡터를 받는다.
     * 같은 공급자가 만든 벡터끼리만 비교할 수 있으므로 provider를 벡터와 함께 저장해야 한다.
     *
     * @param  list<string>  $texts  최대 64개, 각 4000자 이하
     * @return array{vectors: list<list<float>>, provider: 'gemini'|'openai'|'hash', dimensions: int}
     */
    public function embed(array $texts): array
    {
        $usage = app(AiUsage::class);

        $result = $this->post('/embed', [
            'texts' => array_values($texts),
            'allow_external' => $usage->embedRemainingToday() !== 0,
            'allow_openai' => $usage->openaiRemainingToday() !== 0,
        ]);

        if (($result['provider'] ?? 'hash') !== 'hash') {
            $usage->recordEmbedding();
        }

        return $result;
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

    /**
     * 쿼리 임베딩에 외부 공급자(Gemini, OpenAI)를 썼다면 임베딩 일일 사용량에 더한다. 해시 벡터는 비용이 없으므로 세지 않는다.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function recordEmbeddingUsage(array $result): array
    {
        if (in_array($result['embedding_provider'] ?? null, ['gemini', 'openai'], true)) {
            app(AiUsage::class)->recordEmbedding();
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $payload, ?int $timeout = null): array
    {
        try {
            $response = Http::baseUrl(config('services.ai.url'))
                ->withHeaders(['X-Internal-Token' => (string) config('services.ai.token')])
                ->timeout($timeout ?? config('services.ai.timeout'))
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
