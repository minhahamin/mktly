<div>
    @if (session('notice'))
        <div x-data x-init="$nextTick(() => $dispatch('notify', { message: @js(session('notice')) }))"></div>
    @endif

    <x-page-header title="브랜드 문서" subtitle="톤앤매너, 금칙어, 브랜드 소개를 등록하면 AI 카피가 이를 참고합니다.">
        @if ($this->canManage)
            <x-glass.button href="/brand-documents/create" icon="plus">문서 등록</x-glass.button>
        @endif
    </x-page-header>

    @if (count($this->documents) === 0)
        <x-glass.card strong class="py-14 text-center">
            <span class="glass tint-lavender mx-auto grid size-14 place-items-center rounded-full"><x-icon name="file" :size="24" /></span>
            <p class="mt-4 font-semibold">아직 등록한 브랜드 문서가 없습니다</p>
            <p class="mt-1 text-sm text-ink-500">말투, 자주 쓰는 표현, 쓰지 않는 표현을 적어 두면 AI 카피에 반영됩니다.</p>
        </x-glass.card>
    @else
        <p class="mb-3 text-xs text-ink-500">{{ count($this->documents) }} / {{ $this->maxDocuments() }}개 등록</p>
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->documents as $document)
                <x-glass.card strong wire:key="brand-document-{{ $document['id'] }}" class="flex flex-col">
                    {{-- 카드 본문을 누르면 전체 내용을 연다. 삭제 버튼과 겹치지 않도록 버튼을 나눈다. --}}
                    <button type="button" wire:click="openDocument({{ $document['id'] }})" class="focus-ring -m-2 flex flex-1 flex-col rounded-2xl p-2 text-left transition hover:bg-white/50" aria-label="{{ $document['title'] }} 전체 내용 보기">
                        <span class="flex w-full items-start justify-between gap-3">
                            <span class="min-w-0 break-words text-lg font-bold">{{ $document['title'] }}</span>
                            @if ($document['ready'])
                                <span class="glass tint-mint shrink-0 rounded-full px-3 py-1 text-xs font-semibold">검색 가능</span>
                            @else
                                <span class="glass tint-peach shrink-0 rounded-full px-3 py-1 text-xs font-semibold">준비 중</span>
                            @endif
                        </span>

                        <span class="mt-3 block flex-1 break-words text-sm text-ink-700">{{ $document['preview'] }}</span>
                        <span class="mt-3 flex w-full items-center justify-between gap-3 text-xs text-ink-500">
                            <span>조각 {{ $document['chunks'] }}개 · {{ $document['created_at']->format('Y-m-d') }} 등록</span>
                            <span class="font-semibold text-pink-700">전체 보기</span>
                        </span>
                    </button>

                    @if ($this->canManage)
                        <div class="mt-4 flex justify-end">
                            <button type="button" wire:click="confirmDelete({{ $document['id'] }})" class="focus-ring grid size-9 place-items-center rounded-full text-ink-500 hover:bg-white/70 hover:text-danger" aria-label="{{ $document['title'] }} 삭제">
                                <x-icon name="trash" :size="16" />
                            </button>
                        </div>
                    @endif
                </x-glass.card>
            @endforeach
        </div>
    @endif

    @if ($this->viewingDocument)
        <x-glass.modal :title="$this->viewingDocument['title']" close="closeDocument" wide>
            <p class="-mt-3 mb-4 text-xs text-ink-500">조각 {{ $this->viewingDocument['chunks'] }}개 · {{ $this->viewingDocument['created_at']->format('Y-m-d') }} 등록</p>
            <div class="whitespace-pre-line break-words rounded-2xl bg-white/70 p-4 text-sm leading-relaxed text-ink-900">{{ $this->viewingDocument['content'] }}</div>
            <div class="mt-6 flex justify-end">
                <x-glass.button type="button" tint="plain" wire:click="closeDocument">닫기</x-glass.button>
            </div>
        </x-glass.modal>
    @endif

    @if ($deletingId)
        <x-glass.modal title="브랜드 문서를 삭제할까요?" close="cancelDelete">
            <p class="text-sm text-ink-700">삭제하면 이 문서는 더 이상 AI 카피에 반영되지 않습니다. 이미 만든 캠페인은 바뀌지 않습니다.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-glass.button type="button" tint="plain" wire:click="cancelDelete">취소</x-glass.button>
                <x-glass.button type="button" tint="peach" icon="trash" wire:click="delete">삭제</x-glass.button>
            </div>
        </x-glass.modal>
    @endif
</div>
