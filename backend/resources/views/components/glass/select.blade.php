@props(['label' => null, 'error' => null, 'id' => null])

@php($id ??= 'f-'.substr(md5(uniqid('', true)), 0, 8))

<div class="w-full">
    @if ($label)
        <label for="{{ $id }}" class="mb-1.5 block text-sm font-medium text-ink-700">{{ $label }}</label>
    @endif

    <div class="glass-strong focus-within:ring-2 focus-within:ring-pink-500/60 relative flex h-11 items-center rounded-full px-4 {{ $error ? 'ring-2 ring-danger/60' : '' }}">
        <select id="{{ $id }}" {{ $attributes->class('w-full appearance-none bg-transparent pr-6 text-sm text-ink-900 outline-none') }}>
            {{ $slot }}
        </select>
        <x-icon name="chevron-down" :size="16" class="pointer-events-none absolute right-4 text-ink-500" />
    </div>

    @if ($error)
        <p class="mt-1.5 px-2 text-xs text-danger">{{ $error }}</p>
    @endif
</div>
