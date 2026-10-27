@props(['title', 'subtitle' => null])

<div class="mb-8 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $title }}</h1>
        @if ($subtitle)<p class="mt-1 text-sm text-ink-500">{{ $subtitle }}</p>@endif
    </div>
    @if ($slot->isNotEmpty())
        <div class="flex flex-wrap items-center gap-3">{{ $slot }}</div>
    @endif
</div>
