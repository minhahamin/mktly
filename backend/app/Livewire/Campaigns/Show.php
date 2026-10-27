<?php

namespace App\Livewire\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\CampaignMessage;
use App\Models\TrackedLink;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::app')]
#[Title('캠페인 상세')]
class Show extends Component
{
    use WithPagination;

    public Campaign $campaign;

    public function mount(Campaign $campaign): void
    {
        $this->authorize('viewAny', Campaign::class);
        $this->campaign = $campaign;
    }

    /** 예약을 취소하고 임시저장 상태로 되돌린다. */
    public function cancelSchedule(): void
    {
        $this->campaign->refresh();
        $this->authorize('update', $this->campaign);

        if ($this->campaign->status === 'scheduled') {
            $this->campaign->update(['status' => 'draft', 'scheduled_at' => null]);
            $this->dispatch('notify', message: '예약을 취소했습니다.');
        }
    }

    /** 발송 중일 때 폴링으로 호출되어 상태와 수치를 새로 읽는다. */
    public function refreshCampaign(): void
    {
        $this->campaign->refresh();
    }

    /** @return array{sent: int, pending: int, failed: int, opened: int, clicked: int, clicks: int, conversions: int, total: int} */
    #[Computed]
    public function stats(): array
    {
        $messages = CampaignMessage::where('campaign_id', $this->campaign->id);
        $events = CampaignEvent::where('campaign_id', $this->campaign->id);

        return [
            'total' => (clone $messages)->count(),
            'sent' => (clone $messages)->where('status', 'sent')->count(),
            'pending' => (clone $messages)->where('status', 'pending')->count(),
            'failed' => (clone $messages)->where('status', 'failed')->count(),
            'opened' => (clone $messages)->whereNotNull('opened_at')->count(),
            'clicked' => (clone $events)->where('type', CampaignEvent::CLICK)->distinct()->count('contact_id'),
            'clicks' => (clone $events)->where('type', CampaignEvent::CLICK)->count(),
            'conversions' => (clone $events)->where('type', CampaignEvent::CONVERSION)->distinct()->count('contact_id'),
        ];
    }

    /** 링크별 클릭 수 (많은 순) */
    #[Computed]
    public function links()
    {
        return TrackedLink::where('campaign_id', $this->campaign->id)
            ->withCount(['clicks as click_count'])
            ->orderByDesc('click_count')
            ->get();
    }

    #[Computed]
    public function recipients()
    {
        return CampaignMessage::with('contact:id,name,email')
            ->where('campaign_id', $this->campaign->id)
            ->orderByRaw("case status when 'failed' then 0 when 'pending' then 1 else 2 end")
            ->orderBy('id')
            ->paginate(10);
    }

    public function render()
    {
        return view('livewire.campaigns.show');
    }
}
