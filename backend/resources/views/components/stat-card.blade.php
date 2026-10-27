@props(['label', 'value', 'icon', 'delta' => null, 'tint' => 'pink'])

@php
    $tints = ['pink' => 'tint-pink', 'lavender' => 'tint-lavender', 'mint' => 'tint-mint', 'peach' => 'tint-peach'];
    $up = $delta !== null && $delta >= 0;
@endphp

<x-glass.card padding="p-5">
    <div class="flex items-start justify-between">
        <p class="text-sm font-medium text-ink-500">{{ $label }}</p>
        <span class="glass {{ $tints[$tint] ?? '' }} grid size-10 place-items-center rounded-2xl text-ink-900">
            <x-icon :name="$icon" :size="18" />
        </span>
    </div>
    <p class="mt-3 text-3xl font-bold tracking-tight">{{ $value }}</p>
    <p class="mt-2 text-xs {{ $delta === null ? 'text-ink-500' : ($up ? 'text-ok' : 'text-danger') }}">
        @if ($delta === null)
            이전 기간 데이터 없음
        @else
            <span class="font-semibold">{{ $up ? '+' : '' }}{{ number_format($delta, 1) }}%</span>
            <span class="text-ink-500">직전 동일 기간 대비</span>
        @endif
    </p>
</x-glass.card>
