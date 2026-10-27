@props(['label' => null])

<label class="inline-flex cursor-pointer items-center gap-3">
    <input type="checkbox" {{ $attributes->class('peer sr-only') }}>
    <span class="glass-strong grid size-6 place-items-center rounded-lg text-transparent transition peer-checked:tint-pink peer-checked:text-ink-900 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-pink-600">
        <x-icon name="check" :size="14" />
    </span>
    @if ($label)<span class="text-sm text-ink-700">{{ $label }}</span>@endif
</label>
