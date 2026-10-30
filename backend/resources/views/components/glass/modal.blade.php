@props(['title', 'close' => 'closeForm', 'wide' => false])

{{-- 서버 상태(@if)로 열고 닫는 모달. 닫기 동작은 Livewire 메서드 이름으로 받는다. --}}
<div class="fixed inset-0 z-[60] grid place-items-center p-4" role="dialog" aria-modal="true" aria-label="{{ $title }}"
     x-data @keydown.escape.window="$wire.{{ $close }}()">
    <div class="absolute inset-0 bg-ink-900/25" wire:click="{{ $close }}"></div>

    <div @class(['glass-strong relative max-h-[90vh] w-full overflow-y-auto rounded-3xl p-6 sm:p-8', 'max-w-2xl' => $wide, 'max-w-lg' => ! $wide])>
        <div class="mb-6 flex items-start justify-between gap-4">
            <h2 class="text-xl font-bold tracking-tight">{{ $title }}</h2>
            <button type="button" wire:click="{{ $close }}" class="focus-ring grid size-9 place-items-center rounded-full text-ink-500 hover:bg-white/60" aria-label="닫기">
                <x-icon name="x" :size="18" />
            </button>
        </div>

        {{ $slot }}
    </div>
</div>
