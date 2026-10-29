<?php

namespace App\Livewire\BrandDocuments;

use App\Models\BrandDocument;
use App\Services\Brand\BrandDocuments;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('브랜드 문서')]
class Index extends Component
{
    public ?int $deletingId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', BrandDocument::class);
    }

    public function confirmDelete(int $id): void
    {
        $this->authorize('delete', BrandDocument::findOrFail($id));
        $this->deletingId = $id;
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
    }

    public function delete(): void
    {
        $document = BrandDocument::findOrFail($this->deletingId);
        $this->authorize('delete', $document);
        $document->delete();

        $this->deletingId = null;
        $this->dispatch('notify', message: '브랜드 문서를 삭제했습니다.');
    }

    /** 문서별 조각 수와 임베딩이 끝난 조각 수를 붙여 돌려준다. 검색에는 임베딩이 끝난 조각만 쓰인다. */
    #[Computed]
    public function documents(): array
    {
        return BrandDocument::query()
            ->withCount(['chunks', 'chunks as embedded_count' => fn ($q) => $q->whereNotNull('embedding')])
            ->latest()
            ->get()
            ->map(fn (BrandDocument $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'preview' => mb_strimwidth(preg_replace('/\s+/u', ' ', $document->content), 0, 140, '...'),
                'chunks' => $document->chunks_count,
                'ready' => $document->chunks_count > 0 && $document->embedded_count === $document->chunks_count,
                'created_at' => $document->created_at,
            ])
            ->all();
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()->can('create', BrandDocument::class);
    }

    public function maxDocuments(): int
    {
        return BrandDocuments::MAX_DOCUMENTS;
    }

    public function render()
    {
        return view('livewire.brand-documents.index');
    }
}
