<?php

namespace App\Services\Ai;

use App\Models\CampaignEvent;
use App\Models\CampaignMessage;
use App\Models\Contact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 고객별 행동 지표(구매 최근성, 구매액, 메일 반응)를 AI 서비스 입력 형태로 만든다.
 * 이메일, 이름 같은 개인 식별 정보는 AI 서비스로 보내지 않는다.
 */
class ContactFeatureBuilder
{
    /** AI 서비스가 한 번에 받을 수 있는 최대 고객 수 */
    public const MAX_CONTACTS = 20000;

    /** @return list<array{id: int, recency_days: int|null, tenure_days: int, total_spent: int, received: int, opens: int, clicks: int}> */
    public function forWorkspace(int $workspaceId): array
    {
        $engagement = $this->messageStats($workspaceId);
        $clicks = $this->clickStats($workspaceId);
        $today = now()->startOfDay();

        return Contact::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->orderBy('id')
            ->limit(self::MAX_CONTACTS)
            ->get(['id', 'joined_at', 'created_at', 'last_purchase_at', 'total_spent'])
            ->map(function (Contact $c) use ($engagement, $clicks, $today) {
                $joined = $c->joined_at ?? $c->created_at;

                return [
                    'id' => $c->id,
                    'recency_days' => $c->last_purchase_at ? $this->daysBetween($c->last_purchase_at, $today) : null,
                    'tenure_days' => $joined ? $this->daysBetween($joined, $today) : 0,
                    'total_spent' => (int) $c->total_spent,
                    'received' => (int) ($engagement[$c->id]->received ?? 0),
                    'opens' => (int) ($engagement[$c->id]->opens ?? 0),
                    'clicks' => (int) ($clicks[$c->id] ?? 0),
                ];
            })
            ->all();
    }

    private function daysBetween($from, $to): int
    {
        return max(0, (int) abs($from->startOfDay()->diffInDays($to, false)));
    }

    /** @return Collection<int, object{received: int, opens: int}> */
    private function messageStats(int $workspaceId)
    {
        return CampaignMessage::query()
            ->join('campaigns', 'campaigns.id', '=', 'campaign_messages.campaign_id')
            ->where('campaigns.workspace_id', $workspaceId)
            ->where('campaign_messages.status', 'sent')
            ->groupBy('campaign_messages.contact_id')
            ->selectRaw('campaign_messages.contact_id as contact_id, COUNT(*) as received, SUM(CASE WHEN campaign_messages.opened_at IS NULL THEN 0 ELSE 1 END) as opens')
            ->get()
            ->keyBy('contact_id');
    }

    /** @return Collection<int, int> */
    private function clickStats(int $workspaceId)
    {
        return CampaignEvent::query()
            ->join('campaigns', 'campaigns.id', '=', 'campaign_events.campaign_id')
            ->where('campaigns.workspace_id', $workspaceId)
            ->where('campaign_events.type', CampaignEvent::CLICK)
            ->groupBy('campaign_events.contact_id')
            ->select('campaign_events.contact_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'contact_id');
    }
}
