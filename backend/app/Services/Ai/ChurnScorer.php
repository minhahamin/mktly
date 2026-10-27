<?php

namespace App\Services\Ai;

use App\Models\Workspace;
use DomainException;

/** 워크스페이스 전체 고객의 이탈 확률을 계산해 contacts.churn_score에 저장한다. */
class ChurnScorer
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly ContactFeatureBuilder $features,
        private readonly BulkContactUpdater $updater,
    ) {}

    /**
     * @return array<string, mixed> AI 서비스가 돌려준 모델 지표(method, auc, trained_on 등)
     *
     * @throws AiUnavailableException AI 서비스 호출 실패
     * @throws DomainException 고객이 없는 경우
     */
    public function run(int $workspaceId): array
    {
        $contacts = $this->features->forWorkspace($workspaceId);

        if ($contacts === []) {
            throw new DomainException('이탈 점수를 계산할 고객이 없습니다.');
        }

        $result = $this->ai->scoreChurn($contacts);

        $scores = [];
        foreach ($result['scores'] as $row) {
            $scores[$row['id']] = $row['score'];
        }

        $this->updater->update($workspaceId, 'churn_score', $scores);

        Workspace::whereKey($workspaceId)->update([
            'churn_scored_at' => now(),
            'churn_meta' => json_encode($result['metrics'], JSON_UNESCAPED_UNICODE),
        ]);

        return $result['metrics'];
    }
}
