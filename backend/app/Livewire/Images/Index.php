<?php

namespace App\Livewire\Images;

use App\Models\GeneratedImage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('이미지')]
class Index extends Component
{
    public ?string $deletingSet = null;

    public function mount(): void
    {
        $this->authorize('viewAny', GeneratedImage::class);
    }

    public function confirmDelete(string $set): void
    {
        $this->authorize('delete', GeneratedImage::query()->withoutData()->where('set_uuid', $set)->firstOrFail());
        $this->deletingSet = $set;
    }

    public function cancelDelete(): void
    {
        $this->deletingSet = null;
    }

    public function delete(): void
    {
        $first = GeneratedImage::query()->withoutData()->where('set_uuid', $this->deletingSet)->firstOrFail();
        $this->authorize('delete', $first);

        GeneratedImage::query()->where('set_uuid', $this->deletingSet)->delete();

        $this->deletingSet = null;
        $this->dispatch('notify', message: '이미지 세트를 삭제했습니다.');
    }

    /**
     * 최근 세트부터, 세트마다 장 목록을 붙여 돌려준다. PNG 본문은 읽지 않는다.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function sets(): array
    {
        return GeneratedImage::query()->withoutData()->orderByDesc('id')->get()
            ->groupBy('set_uuid')
            ->map(function ($images, $uuid) {
                $images = $images->sortBy('slide_index')->values();
                $first = $images->first();

                return [
                    'uuid' => $uuid,
                    'title' => $first->title,
                    'kind' => GeneratedImage::KINDS[$first->kind] ?? $first->kind,
                    'ratio' => $first->ratio,
                    'background' => $first->background_provider === 'gemini' ? 'AI 배경' : '템플릿 배경',
                    'created_at' => $first->created_at,
                    'images' => $images->map(fn ($image) => ['id' => $image->id, 'number' => $image->slide_index + 1, 'title' => $image->slide_title])->all(),
                ];
            })
            ->values()
            ->all();
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()->can('create', GeneratedImage::class);
    }

    public function render()
    {
        return view('livewire.images.index');
    }
}
