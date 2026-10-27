@props(['strong' => false, 'padding' => 'p-6'])

<div {{ $attributes->class([$strong ? 'glass-strong' : 'glass', 'rounded-3xl', $padding]) }}>
    {{ $slot }}
</div>
