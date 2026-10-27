@props(['title' => null, 'heading' => null])

@php
    $nav = [
        ['href' => '/dashboard', 'match' => 'dashboard*', 'icon' => 'dashboard', 'label' => '대시보드'],
        ['href' => '/campaigns', 'match' => 'campaigns*', 'icon' => 'megaphone', 'label' => '캠페인'],
        ['href' => '/contacts', 'match' => 'contacts*', 'icon' => 'users', 'label' => '고객'],
        ['href' => '/segments', 'match' => 'segments*', 'icon' => 'layers', 'label' => '세그먼트'],
        ['href' => '/settings', 'match' => 'settings*', 'icon' => 'settings', 'label' => '설정'],
    ];
@endphp

<x-layouts.base :title="$title ?? $heading">
    <div x-data="{ open: false }" class="min-h-screen lg:flex">
        {{-- 모바일 상단바 --}}
        <header class="glass-strong sticky top-0 z-30 flex h-14 items-center justify-between px-4 lg:hidden">
            <x-logo :size="30" />
            <button type="button" class="focus-ring grid size-10 place-items-center rounded-full" @click="open = true" aria-label="메뉴 열기">
                <x-icon name="menu" />
            </button>
        </header>

        {{-- 사이드바 --}}
        <div x-cloak x-show="open" class="fixed inset-0 z-40 bg-ink-900/20 lg:hidden" @click="open = false"></div>
        <aside class="glass fixed inset-y-3 left-3 z-50 flex w-64 -translate-x-[120%] flex-col overflow-y-auto rounded-3xl p-4 transition-transform duration-200 lg:sticky lg:top-3 lg:h-[calc(100vh-1.5rem)] lg:translate-x-0"
               :class="{ 'translate-x-0': open }">
            <div class="flex items-center justify-between px-2 py-2">
                <x-logo />
                <button type="button" class="focus-ring grid size-9 place-items-center rounded-full lg:hidden" @click="open = false" aria-label="메뉴 닫기">
                    <x-icon name="x" :size="18" />
                </button>
            </div>

            <nav class="mt-6 flex flex-1 flex-col gap-1.5" aria-label="주 메뉴">
                @foreach ($nav as $item)
                    @php($active = request()->is($item['match']))
                    <a href="{{ $item['href'] }}" @if ($active) aria-current="page" @endif
                       class="focus-ring flex h-11 items-center gap-3 rounded-full px-4 text-sm font-semibold transition {{ $active ? 'glass-strong tint-pink text-ink-900' : 'text-ink-700 hover:bg-white/50' }}">
                        <x-icon :name="$item['icon']" :size="18" />
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>

            @auth
                <x-ai-model-card />

                <div class="glass-strong mt-3 flex items-center gap-3 rounded-2xl p-3">
                    <span class="tint-pink grid size-9 place-items-center rounded-full border text-sm font-bold">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold">{{ auth()->user()->name }}</p>
                        <p class="truncate text-xs text-ink-500">{{ auth()->user()->email }}</p>
                    </div>
                    <form method="POST" action="/logout">
                        @csrf
                        <button class="focus-ring grid size-9 place-items-center rounded-full text-ink-500 hover:bg-white/60" aria-label="로그아웃">
                            <x-icon name="logout" :size="18" />
                        </button>
                    </form>
                </div>
            @endauth
        </aside>

        {{-- 본문 --}}
        <main class="min-w-0 flex-1 px-4 pb-12 pt-6 sm:px-8 lg:pt-8">
            @if (auth()->user()->is_demo)
                <div class="glass tint-lavender mb-6 flex flex-wrap items-center justify-between gap-3 rounded-3xl px-5 py-3 text-sm" role="status">
                    <span><strong>데모 계정</strong>으로 둘러보는 중입니다. 읽기 전용이라 데이터를 바꿀 수 없어요.</span>
                    @if (config('mktly.signup.enabled'))
                        <form method="POST" action="/logout">
                            @csrf
                            <input type="hidden" name="next" value="register">
                            <button class="focus-ring glass-strong rounded-full px-4 py-1.5 text-xs font-semibold hover:brightness-105">나만의 워크스페이스 만들기</button>
                        </form>
                    @endif
                </div>
            @endif

            @if ($heading)
                <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
                    <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $heading }}</h1>
                    @isset($actions)<div class="flex items-center gap-3">{{ $actions }}</div>@endisset
                </div>
            @endif
            {{ $slot }}
        </main>
    </div>

    {{-- 토스트: Livewire에서 dispatch('notify', message: '...')로 띄운다. --}}
    <div x-data="{ show: false, message: '', timer: null }"
         @notify.window="message = $event.detail.message; show = true; clearTimeout(timer); timer = setTimeout(() => show = false, 3200)"
         x-cloak x-show="show" x-transition.opacity role="status" aria-live="polite"
         class="glass-strong tint-pink fixed bottom-6 left-1/2 z-[70] -translate-x-1/2 rounded-full px-6 py-3 text-sm font-semibold">
        <span x-text="message"></span>
    </div>
</x-layouts.base>
