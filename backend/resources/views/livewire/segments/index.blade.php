<div>
    @if (session('notice'))
        <div x-data x-init="$nextTick(() => $dispatch('notify', { message: @js(session('notice')) }))"></div>
    @endif

    <x-page-header title="세그먼트" subtitle="조건으로 고객 그룹을 만들어 캠페인 대상으로 사용합니다.">
        @if ($this->canManage)
            <x-glass.button href="/segments/create" icon="plus">세그먼트 만들기</x-glass.button>
        @endif
    </x-page-header>

    @if (count($this->segments) === 0)
        <x-glass.card strong class="py-14 text-center">
            <span class="glass tint-lavender mx-auto grid size-14 place-items-center rounded-full"><x-icon name="layers" :size="24" /></span>
            <p class="mt-4 font-semibold">아직 세그먼트가 없습니다</p>
            <p class="mt-1 text-sm text-ink-500">구매액, 가입일, 태그 같은 조건으로 첫 세그먼트를 만들어 보세요.</p>
        </x-glass.card>
    @else
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->segments as $segment)
                <x-glass.card strong wire:key="segment-{{ $segment['id'] }}" class="flex flex-col">
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="text-lg font-bold">{{ $segment['name'] }}</h2>
                        <span class="glass tint-pink shrink-0 rounded-full px-3 py-1 text-xs font-semibold">{{ number_format($segment['count']) }}명</span>
                    </div>

                    <p class="mt-3 text-xs font-medium text-ink-500">{{ $segment['match'] === 'any' ? '아래 조건 중 하나라도 만족' : '아래 조건을 모두 만족' }}</p>
                    <ul class="mt-2 flex flex-1 flex-wrap content-start gap-2">
                        @foreach ($segment['summary'] as $text)
                            <li class="rounded-full border border-line bg-white/60 px-3 py-1 text-xs text-ink-700">{{ $text }}</li>
                        @endforeach
                    </ul>

                    @if ($this->canManage)
                        <div class="mt-5 flex justify-end gap-2">
                            <x-glass.button :href="'/segments/'.$segment['id'].'/edit'" tint="lavender" size="sm" icon="edit">편집</x-glass.button>
                            <button type="button" wire:click="confirmDelete({{ $segment['id'] }})" class="focus-ring grid size-9 place-items-center rounded-full text-ink-500 hover:bg-white/70 hover:text-danger" aria-label="{{ $segment['name'] }} 삭제">
                                <x-icon name="trash" :size="16" />
                            </button>
                        </div>
                    @endif
                </x-glass.card>
            @endforeach
        </div>
    @endif

    @if ($deletingId)
        <x-glass.modal title="세그먼트를 삭제할까요?" close="cancelDelete">
            <p class="text-sm text-ink-700">이 세그먼트를 대상으로 지정한 캠페인은 대상이 비워집니다. 고객 데이터는 삭제되지 않습니다.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-glass.button type="button" tint="plain" wire:click="cancelDelete">취소</x-glass.button>
                <x-glass.button type="button" tint="peach" icon="trash" wire:click="delete">삭제</x-glass.button>
            </div>
        </x-glass.modal>
    @endif
</div>
