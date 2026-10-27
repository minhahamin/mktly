@props(['size' => 36])

<span {{ $attributes->class('inline-flex items-center gap-2.5') }}>
    <span class="glass tint-pink grid place-items-center rounded-2xl text-ink-900" style="width: {{ $size }}px; height: {{ $size }}px">
        <x-icon name="sparkles" :size="(int) ($size * 0.55)" />
    </span>
    <span class="text-xl font-bold tracking-tight text-ink-900">Mktly</span>
</span>
