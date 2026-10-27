<?php

namespace App\Services\Ai;

use App\Models\AiGeneration;

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
}
