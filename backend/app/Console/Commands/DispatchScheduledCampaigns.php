<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\Campaigns\CampaignSender;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('campaigns:dispatch-scheduled')]
#[Description('예약 시각이 지난 캠페인의 발송을 시작한다')]
class DispatchScheduledCampaigns extends Command
{
    public function handle(CampaignSender $sender): int
    {
        $started = 0;

        Campaign::withoutGlobalScopes()
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->each(function (Campaign $campaign) use ($sender, &$started) {
                try {
                    $started += $sender->start($campaign) ? 1 : 0;
                } catch (DomainException $e) {
                    // 예약 시점에 대상이 없어졌다면 실패로 표시해 계속 재시도하지 않게 한다.
                    $campaign->update(['status' => 'failed']);
                    $this->warn("캠페인 #{$campaign->id}: {$e->getMessage()}");
                }
            });

        $this->info("예약 캠페인 {$started}건의 발송을 시작했습니다.");

        return self::SUCCESS;
    }
}
