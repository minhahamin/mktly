<?php

namespace App\Services\Campaigns;

use App\Jobs\SendCampaignChunk;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\TrackedLink;
use App\Services\Segments\SegmentRuleEngine;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CampaignSender
{
    public const CHUNK_SIZE = 500;

    public function __construct(
        private readonly SegmentRuleEngine $engine,
        private readonly MessageRenderer $renderer,
    ) {}

    /** 캠페인의 발송 대상 고객 쿼리. 세그먼트가 없으면 워크스페이스 전체 고객이다. */
    public function recipientQuery(Campaign $campaign): Builder
    {
        $query = Contact::withoutGlobalScopes()->where('workspace_id', $campaign->workspace_id);

        if ($campaign->segment_id) {
            $segment = Segment::withoutGlobalScopes()
                ->where('workspace_id', $campaign->workspace_id)
                ->find($campaign->segment_id);

            if ($segment) {
                $query = $this->engine->apply($query, $segment->rules);
            }
        }

        return $query;
    }

    public function recipientCount(Campaign $campaign): int
    {
        return $this->recipientQuery($campaign)->count();
    }

    /**
     * 발송을 시작한다. 수신자 메시지와 추적 링크를 만들고 청크 단위 큐 잡을 등록한다.
     *
     * @return bool 다른 프로세스가 먼저 시작했거나 발송할 수 없는 상태면 false
     *
     * @throws DomainException 발송 대상이 없는 경우(캠페인은 변경되지 않는다)
     */
    public function start(Campaign $campaign): bool
    {
        if ($this->recipientCount($campaign) === 0) {
            throw new DomainException('발송 대상 고객이 없습니다.');
        }

        // 예약 실행기와 수동 발송이 겹쳐도 한 번만 시작되도록 상태를 원자적으로 선점한다.
        $claimed = Campaign::withoutGlobalScopes()
            ->whereKey($campaign->id)
            ->whereIn('status', ['draft', 'scheduled'])
            ->update(['status' => 'sending', 'scheduled_at' => null]);

        if ($claimed === 0) {
            return false;
        }

        $campaign->refresh();
        $this->createTrackedLinks($campaign);
        $this->createMessages($campaign);

        CampaignMessage::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function ($messages) use ($campaign) {
                SendCampaignChunk::dispatch($campaign->id, $messages->pluck('id')->all());
            });

        return true;
    }

    /** 모든 메시지 처리가 끝났다면 캠페인 상태를 sent(전부 실패면 failed)로 마감한다. */
    public function finalize(int $campaignId): void
    {
        $open = CampaignMessage::where('campaign_id', $campaignId)->where('status', 'pending')->exists();

        if ($open) {
            return;
        }

        $total = CampaignMessage::where('campaign_id', $campaignId)->count();
        $failed = CampaignMessage::where('campaign_id', $campaignId)->where('status', 'failed')->count();

        Campaign::withoutGlobalScopes()
            ->whereKey($campaignId)
            ->where('status', 'sending')
            ->update([
                'status' => $total > 0 && $failed === $total ? 'failed' : 'sent',
                'sent_at' => now(),
            ]);
    }

    private function createTrackedLinks(Campaign $campaign): void
    {
        foreach ($this->renderer->extractUrls($campaign->body) as $url) {
            TrackedLink::firstOrCreate(
                ['campaign_id' => $campaign->id, 'target_url' => $url],
                ['token' => Str::random(40)],
            );
        }
    }

    private function createMessages(Campaign $campaign): void
    {
        $now = now();

        $this->recipientQuery($campaign)->orderBy('id')->select('contacts.id')->chunkById(self::CHUNK_SIZE, function ($contacts) use ($campaign, $now) {
            CampaignMessage::query()->insertOrIgnore($contacts->map(fn ($contact) => [
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'token' => Str::random(40),
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }, 'contacts.id', 'id');
    }
}
