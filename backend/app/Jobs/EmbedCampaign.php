<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Services\Ai\AiClient;
use App\Services\Ai\CopyExamples;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** 발송이 끝난 캠페인의 제목과 본문을 임베딩해 저장한다. AI 서비스 장애 시 간격을 두고 재시도하며, 다시 실행해도 안전하다. */
class EmbedCampaign implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $campaignId) {}

    public function handle(AiClient $ai): void
    {
        $campaign = Campaign::withoutGlobalScopes()->find($this->campaignId);

        if ($campaign === null || $campaign->status !== 'sent') {
            return;
        }

        // 수신자가 적으면 참고 후보가 될 수 없으므로 외부 호출 비용을 쓰지 않는다.
        $delivered = CampaignMessage::where('campaign_id', $campaign->id)->where('status', 'sent')->count();

        if ($delivered < CopyExamples::MIN_RECIPIENTS) {
            return;
        }

        $result = $ai->embed([CopyExamples::textFor($campaign)]);

        $campaign->forceFill([
            'embedding' => array_map(fn ($value) => round((float) $value, 5), $result['vectors'][0]),
            'embedding_provider' => $result['provider'],
        ])->save();
    }
}
