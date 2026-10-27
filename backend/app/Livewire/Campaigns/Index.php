<?php

namespace App\Livewire\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::app')]
#[Title('캠페인')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $deletingId = null;

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function confirmDelete(int $id): void
    {
        $this->authorize('delete', Campaign::findOrFail($id));
        $this->deletingId = $id;
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
    }

    public function delete(): void
    {
        $campaign = Campaign::findOrFail($this->deletingId);
        $this->authorize('delete', $campaign);
        $campaign->delete();

        $this->deletingId = null;
        $this->dispatch('notify', message: '캠페인을 삭제했습니다.');
    }

    #[Computed]
    public function campaigns()
    {
        $term = addcslashes(trim($this->search), '%_\\');

        return Campaign::query()
            ->with('segment:id,name')
            ->withCount([
                'messages as sent_count' => fn ($q) => $q->where('status', 'sent'),
                'messages as opened_count' => fn ($q) => $q->whereNotNull('opened_at'),
                'events as click_count' => fn ($q) => $q->where('type', CampaignEvent::CLICK),
            ])
            ->when(in_array($this->status, Campaign::STATUSES, true), fn ($q) => $q->where('status', $this->status))
            ->when($term !== '', fn ($q) => $q->where('name', 'like', "%{$term}%"))
            ->latest('id')
            ->paginate(10);
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()->can('create', Campaign::class);
    }

    public function render()
    {
        return view('livewire.campaigns.index');
    }
}
