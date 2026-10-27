<?php

namespace App\Services\Ai;

use DomainException;

/** 고객을 k개 그룹으로 나눠 contacts.cluster_id에 저장하고, 그룹 요약을 돌려준다. */
class ClusterRecommender
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly ContactFeatureBuilder $features,
        private readonly BulkContactUpdater $updater,
    ) {}

    /**
     * @return list<array{id: int, name: string, description: string, size: int, avg_spent: int, avg_recency_days: int|null, open_rate: float}>
     *
     * @throws AiUnavailableException AI 서비스 호출 실패(고객 수 부족 포함)
     * @throws DomainException 고객이 없는 경우
     */
    public function run(int $workspaceId, int $k): array
    {
        $contacts = $this->features->forWorkspace($workspaceId);

        if ($contacts === []) {
            throw new DomainException('분석할 고객이 없습니다. 먼저 고객을 추가해 주세요.');
        }

        $result = $this->ai->cluster($contacts, max(2, min(8, $k)));

        $assignments = [];
        foreach ($result['assignments'] as $row) {
            $assignments[$row['id']] = $row['cluster'];
        }

        $this->updater->update($workspaceId, 'cluster_id', $assignments);

        return $result['clusters'];
    }
}
