@props(['label' => null, 'error' => null, 'id' => null])

@php($id ??= 'f-'.substr(md5(uniqid('', true)), 0, 8))

<div class="w-full">
    @if ($label)
        <label for="{{ $id }}" class="mb-1.5 block text-sm font-medium text-ink-700">{{ $label }}</label>
    @endif

    <div class="glass-strong focus-within:ring-2 focus-within:ring-pink-500/60 rounded-3xl px-4 py-3 {{ $error ? 'ring-2 ring-danger/60' : '' }}">
        <textarea id="{{ $id }}" {{ $attributes->class('min-h-32 w-full resize-y bg-transparent text-sm text-ink-900 placeholder:text-ink-500/70 outline-none') }}
                  @if ($error) aria-invalid="true" @endif>{{ $slot }}</textarea>
    </div>

    @if ($error)
        <p class="mt-1.5 px-2 text-xs text-danger">{{ $error }}</p>
    @endif
</div>
