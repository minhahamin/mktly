<?php

namespace App\Livewire\Segments;

use App\Models\Segment;
use App\Services\Segments\SegmentRuleEngine;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('세그먼트')]
class Index extends Component
{
    public ?int $deletingId = null;

    public function confirmDelete(int $id): void
    {
        $this->authorize('delete', Segment::findOrFail($id));
        $this->deletingId = $id;
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
    }

    public function delete(): void
    {
        $segment = Segment::findOrFail($this->deletingId);
        $this->authorize('delete', $segment);
        $segment->delete();

        $this->deletingId = null;
        $this->dispatch('notify', message: '세그먼트를 삭제했습니다.');
    }

    /** 세그먼트별 대상 수와 조건 요약을 붙여 돌려준다. */
    #[Computed]
    public function segments(): array
    {
        $engine = app(SegmentRuleEngine::class);

        return Segment::query()->orderBy('name')->get()->map(function (Segment $segment) use ($engine) {
            $rules = $engine->normalize($segment->rules);

            return [
                'id' => $segment->id,
                'name' => $segment->name,
                'match' => $rules['match'],
                'summary' => array_map(fn ($c) => $engine->describe($c), $rules['conditions']),
                'count' => $engine->count($segment->rules),
            ];
        })->all();
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()->can('create', Segment::class);
    }

    public function render()
    {
        return view('livewire.segments.index');
    }
}
