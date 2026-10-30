<?php

namespace App\Services\Ai;

use App\Models\AiGeneration;
use App\Models\GeneratedImage;
use Illuminate\Support\Facades\Cache;

/** LLM, 이미지 모델 호출 비용을 보호하기 위한 일일 사용량 계산. */
class AiUsage
{
    /**
     * 오늘 더 만들 수 있는 AI 생성(카피, 카드뉴스 초안) 횟수. 일일 한도(MKTLY_AI_COPY_DAILY_LIMIT)가 없으면 null이다.
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
            ->whereIn('type', AiGeneration::LLM_TYPES)
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
            ->whereIn('type', AiGeneration::LLM_TYPES)
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

    /**
     * 오늘 더 만들 수 있는 AI 배경 삽화 세트 수. 상한(MKTLY_AI_IMAGE_DAILY_LIMIT)이 없으면 기능이 꺼진 것으로 보고 0이다.
     * 실제로 AI 배경으로 저장된 세트만 센다. 호출이 실패해 템플릿 배경으로 대체된 세트는 세지 않는다.
     */
    public function imageRemainingToday(): int
    {
        $limit = (int) config('mktly.ai.image_daily_limit');

        if ($limit <= 0) {
            return 0;
        }

        $used = GeneratedImage::withoutGlobalScopes()
            ->where('background_provider', GeneratedImage::BACKGROUND_GEMINI)
            ->where('created_at', '>=', now()->startOfDay())
            ->distinct()
            ->count('set_uuid');

        return max(0, $limit - $used);
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
