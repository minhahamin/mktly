@props(['tint' => 'pink', 'size' => 'md', 'href' => null, 'type' => 'button', 'icon' => null])

@php
    $sizes = ['sm' => 'h-9 px-4 text-sm gap-1.5', 'md' => 'h-11 px-6 text-sm gap-2', 'lg' => 'h-12 px-8 text-base gap-2'];
    $tints = ['pink' => 'tint-pink', 'lavender' => 'tint-lavender', 'mint' => 'tint-mint', 'peach' => 'tint-peach', 'plain' => ''];
    $classes = 'glass focus-ring inline-flex items-center justify-center rounded-full font-semibold text-ink-900 '
        .'transition duration-150 hover:-translate-y-px hover:brightness-105 active:translate-y-0 active:brightness-95 '
        .'disabled:pointer-events-none disabled:opacity-50 '.($sizes[$size] ?? $sizes['md']).' '.($tints[$tint] ?? '');
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-icon :name="$icon" :size="18" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-icon :name="$icon" :size="18" />@endif
        {{ $slot }}
    </button>
@endif
