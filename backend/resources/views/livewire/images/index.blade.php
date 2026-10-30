<div>
    @if (session('notice'))
        <div x-data x-init="$nextTick(() => $dispatch('notify', { message: @js(session('notice')) }))"></div>
    @endif

    <x-page-header title="이미지" subtitle="카드뉴스와 블로그 대표 이미지를 만들고 내려받습니다.">
        @if ($this->canManage)
            <x-glass.button href="/images/create" icon="plus">카드뉴스 만들기</x-glass.button>
        @endif
    </x-page-header>

    @if (count($this->sets) === 0)
        <x-glass.card strong class="py-14 text-center">
            <span class="glass tint-lavender mx-auto grid size-14 place-items-center rounded-full"><x-icon name="image" :size="24" /></span>
            <p class="mt-4 font-semibold">아직 만든 이미지가 없습니다</p>
            <p class="mt-1 text-sm text-ink-500">주제를 입력하면 AI가 문구를 쓰고, 파스텔 카드로 이미지를 만들어 드립니다.</p>
        </x-glass.card>
    @else
        <p class="mb-3 text-xs text-ink-500">{{ count($this->sets) }} / {{ \App\Models\GeneratedImage::MAX_SETS }}개 보관 중</p>
        <div class="space-y-5">
            @foreach ($this->sets as $set)
                <x-glass.card strong wire:key="image-set-{{ $set['uuid'] }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="break-words text-lg font-bold">{{ $set['title'] }}</h2>
                            <p class="mt-1 text-xs text-ink-500">{{ $set['kind'] }} · {{ $set['ratio'] }} · {{ count($set['images']) }}장 · {{ $set['background'] }} · {{ $set['created_at']->format('Y-m-d H:i') }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <x-glass.button :href="'/images/sets/'.$set['uuid'].'/zip'" tint="lavender" size="sm" icon="download">ZIP 받기</x-glass.button>
                            @if ($this->canManage)
                                <button type="button" wire:click="confirmDelete('{{ $set['uuid'] }}')" class="focus-ring grid size-9 place-items-center rounded-full text-ink-500 hover:bg-white/70 hover:text-danger" aria-label="{{ $set['title'] }} 세트 삭제">
                                    <x-icon name="trash" :size="16" />
                                </button>
                            @endif
                        </div>
                    </div>

                    <ul class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        @foreach ($set['images'] as $image)
                            <li wire:key="image-{{ $image['id'] }}">
                                <a href="/images/{{ $image['id'] }}/file" target="_blank" rel="noopener" class="focus-ring block overflow-hidden rounded-2xl border border-line bg-white/60">
                                    <img src="/images/{{ $image['id'] }}/file" alt="{{ $image['number'] }}장: {{ $image['title'] }}" loading="lazy" class="block w-full">
                                </a>
                                <a href="/images/{{ $image['id'] }}/file?download=1" class="focus-ring mt-1.5 inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs text-ink-500 hover:bg-white/70 hover:text-ink-900">
                                    <x-icon name="download" :size="12" /> {{ $image['number'] }}장 받기
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </x-glass.card>
            @endforeach
        </div>
    @endif

    @if ($deletingSet)
        <x-glass.modal title="이미지 세트를 삭제할까요?" close="cancelDelete">
            <p class="text-sm text-ink-700">세트의 모든 이미지가 삭제되며 되돌릴 수 없습니다. 이미 내려받은 파일은 그대로입니다.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-glass.button type="button" tint="plain" wire:click="cancelDelete">취소</x-glass.button>
                <x-glass.button type="button" tint="peach" icon="trash" wire:click="delete">삭제</x-glass.button>
            </div>
        </x-glass.modal>
    @endif
</div>
