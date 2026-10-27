@props(['label' => null])

{{-- wire:model 또는 x-model 로 바인딩되는 체크박스 기반 스위치 --}}
<label class="inline-flex cursor-pointer items-center gap-3">
    <input type="checkbox" {{ $attributes->class('peer sr-only') }}>
    <span class="glass focus-ring relative h-7 w-12 rounded-full transition peer-checked:tint-pink peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-pink-600
                 after:absolute after:left-1 after:top-1 after:size-5 after:rounded-full after:bg-white after:shadow-md after:transition-transform after:content-[''] peer-checked:after:translate-x-5"></span>
    @if ($label)<span class="text-sm text-ink-700">{{ $label }}</span>@endif
</label>
