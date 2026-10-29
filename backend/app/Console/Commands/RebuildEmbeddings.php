<?php

namespace App\Console\Commands;

use App\Jobs\EmbedCampaign;
use App\Models\Campaign;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mktly:embeddings:rebuild {--all : 이미 임베딩이 있는 캠페인도 다시 만든다(임베딩 공급자를 바꾼 뒤에 사용)}')]
#[Description('발송 완료된 캠페인 중 임베딩이 없는 것(--all이면 전부)의 임베딩 작업을 큐에 등록한다. 시드 데이터, 실패한 작업, 공급자 변경 후 복구에 쓴다.')]
class RebuildEmbeddings extends Command
{
    public function handle(): int
    {
        $count = 0;

        Campaign::withoutGlobalScopes()
            ->where('status', 'sent')
            ->when(! $this->option('all'), fn ($query) => $query->whereNull('embedding'))
            ->orderBy('id')
            ->select('id')
            ->chunkById(200, function ($campaigns) use (&$count) {
                foreach ($campaigns as $campaign) {
                    EmbedCampaign::dispatch($campaign->id);
                    $count++;
                }
            });

        $this->info("임베딩 작업 {$count}건을 등록했습니다.");

        return self::SUCCESS;
    }
}
