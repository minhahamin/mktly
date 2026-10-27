<?php

namespace App\Console\Commands;

use App\Jobs\ScoreWorkspaceChurn;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('contacts:score-churn')]
#[Description('모든 워크스페이스의 고객 이탈 점수 갱신 작업을 큐에 등록한다')]
class ScoreChurn extends Command
{
    public function handle(): int
    {
        $count = 0;

        Workspace::query()->each(function (Workspace $workspace) use (&$count) {
            ScoreWorkspaceChurn::dispatch($workspace->id);
            $count++;
        });

        $this->info("워크스페이스 {$count}곳의 이탈 점수 갱신을 등록했습니다.");

        return self::SUCCESS;
    }
}
