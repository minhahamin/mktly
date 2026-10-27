<?php

namespace App\Livewire;

use App\Services\Dashboard\DashboardStats;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('대시보드')]
class Dashboard extends Component
{
    private const ALLOWED_RANGES = [7, 30, 90];

    #[Url]
    public int $days = 30;

    public function mount(): void
    {
        $this->normalizeDays();
    }

    public function updatedDays(): void
    {
        $this->normalizeDays();
    }

    #[Computed]
    public function summary(): array
    {
        return app(DashboardStats::class)->summary($this->days);
    }

    #[Computed]
    public function timeseries(): array
    {
        return app(DashboardStats::class)->timeseries($this->days);
    }

    #[Computed]
    public function campaigns()
    {
        return app(DashboardStats::class)->campaignComparison($this->days);
    }

    public function render()
    {
        return view('livewire.dashboard');
    }

    private function normalizeDays(): void
    {
        if (! in_array($this->days, self::ALLOWED_RANGES, true)) {
            $this->days = 30;
        }
    }
}
