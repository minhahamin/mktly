<?php

namespace App\Services\Ai;

use App\Models\AiGeneration;
use Illuminate\Support\Facades\Cache;

/** LLM 호출 비용을 보호하기 위한 일일 사용량 계산. */
class AiUsage
{
    /**
     * 오늘 더 만들 수 있는 AI 카피 생성 횟수. 일일 한도(MKTLY_AI_COPY_DAILY_LIMIT)가 없으면 null이다.
     * 실제 모델로 생성한 기록만 센다(샘플 모드 결과는 비용이 없으므로 제외).
     * 동시에 여러 요청이 들어오면 한도를 몇 건 넘길 수 있다.
     */
    public function copyRemainingToday(): ?int
    {
        $limit = config('mktly.ai.copy_daily_limit');

        if (! $limit) {
            return null;
        }

        $used = AiGeneration::withoutGlobalScopes()
            ->where('type', AiGeneration::TYPE_COPY)
            ->where('mock', false)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        return max(0, (int) $limit - $used);
    }

    /**
     * 오늘 더 쓸 수 있는 유료 OpenAI 호출 횟수. 상한(MKTLY_AI_OPENAI_DAILY_LIMIT)이 없으면 null이다.
     * 0이면 AI 서비스에 OpenAI를 건너뛰라고 알린다.
     */
    public function openaiRemainingToday(): ?int
    {
        $limit = config('mktly.ai.openai_daily_limit');

        if (! $limit) {
            return null;
        }

        $used = AiGeneration::withoutGlobalScopes()
            ->where('type', AiGeneration::TYPE_COPY)
            ->where('provider', AiGeneration::PROVIDER_OPENAI)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        return max(0, (int) $limit - $used);
    }

    /**
     * 오늘 더 쓸 수 있는 임베딩 외부 호출 횟수. 상한(MKTLY_AI_EMBED_DAILY_LIMIT)이 없으면 null이다.
     * 0이면 AI 서비스에 외부 호출 없이 해시 벡터를 만들라고 알린다.
     * 임베딩은 기록 테이블이 없어서 캐시 카운터로 센다(캐시를 비우면 오늘 사용량도 0으로 돌아간다).
     */
    public function embedRemainingToday(): ?int
    {
        $limit = config('mktly.ai.embed_daily_limit');

        if (! $limit) {
            return null;
        }

        return max(0, (int) $limit - (int) Cache::get($this->embedKey(), 0));
    }

    /** 외부 공급자(Gemini, OpenAI)가 응답한 임베딩 호출 한 건을 오늘 사용량에 더한다. 해시 벡터는 비용이 없으므로 세지 않는다. */
    public function recordEmbedding(): void
    {
        Cache::add($this->embedKey(), 0, now()->endOfDay()->addDay());
        Cache::increment($this->embedKey());
    }

    private function embedKey(): string
    {
        return 'ai:embed-calls:'.now()->toDateString();
    }
}
