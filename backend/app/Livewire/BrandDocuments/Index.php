<?php

namespace App\Livewire\BrandDocuments;

use App\Models\BrandDocument;
use App\Services\Brand\BrandDocuments;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('브랜드 문서')]
class Index extends Component
{
    public ?int $deletingId = null;

    /** 전체 내용을 열어 본 문서 id */
    public ?int $viewingId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', BrandDocument::class);
    }

    /** 카드를 누르면 전체 내용을 보여 준다. 다른 워크스페이스의 문서는 글로벌 스코프 때문에 404다. */
    public function openDocument(int $id): void
    {
        $this->authorize('viewAny', BrandDocument::class);
        $this->viewingId = BrandDocument::findOrFail($id)->id;
    }

    public function closeDocument(): void
    {
        $this->viewingId = null;
    }

    /** @return array{title: string, content: string, chunks: int, created_at: Carbon}|null */
    #[Computed]
    public function viewingDocument(): ?array
    {
        $document = $this->viewingId ? BrandDocument::query()->withCount('chunks')->find($this->viewingId) : null;

        return $document ? [
            'title' => $document->title,
            'content' => $document->content,
            'chunks' => $document->chunks_count,
            'created_at' => $document->created_at,
        ] : null;
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
