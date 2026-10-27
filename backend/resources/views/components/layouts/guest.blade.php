@props(['title' => null])

<x-layouts.base :title="$title">
    <main class="grid min-h-screen place-items-center px-4 py-10">
        <div class="w-full max-w-md">
            <div class="mb-8 flex items-center justify-center gap-3">
                <x-logo />
            </div>
            {{ $slot }}
        </div>
    </main>
</x-layouts.base>
