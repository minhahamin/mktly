<?php

namespace App\Jobs;

use App\Services\Ai\ChurnScorer;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** 워크스페이스 한 곳의 이탈 점수를 갱신한다. AI 서비스 장애 시 간격을 두고 재시도한다. */
class ScoreWorkspaceChurn implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $workspaceId) {}

    public function handle(ChurnScorer $scorer): void
    {
        try {
            $scorer->run($this->workspaceId);
        } catch (DomainException) {
            // 고객이 없는 워크스페이스는 건너뛴다.
        }
    }
}
