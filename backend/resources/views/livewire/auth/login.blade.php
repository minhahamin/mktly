<div>
    <x-glass.card strong padding="p-8">
        <h1 class="text-2xl font-bold tracking-tight">다시 오신 것을 환영합니다</h1>
        <p class="mt-1.5 text-sm text-ink-500">계정으로 로그인해 캠페인과 고객 인사이트를 확인하세요.</p>

        <form wire:submit="login" class="mt-8 space-y-5" novalidate>
            <x-glass.input
                label="이메일"
                icon="mail"
                type="email"
                autocomplete="email"
                placeholder="you@company.com"
                wire:model="email"
                :error="$errors->first('email')"
                autofocus
            />

            <x-glass.input
                label="비밀번호"
                icon="lock"
                type="password"
                autocomplete="current-password"
                placeholder="비밀번호"
                wire:model="password"
                :error="$errors->first('password')"
            />

            <div class="flex items-center justify-between">
                <x-glass.checkbox label="로그인 유지" wire:model="remember" />
            </div>

            <x-glass.button type="submit" size="lg" class="w-full" wire:loading.attr="disabled" wire:target="login">
                <span wire:loading.remove wire:target="login">로그인</span>
                <span wire:loading wire:target="login">확인 중...</span>
            </x-glass.button>
        </form>
    </x-glass.card>

    @if (app()->environment('local'))
        <div class="glass mt-5 flex items-center justify-between gap-3 rounded-3xl px-5 py-4">
            <div class="min-w-0">
                <p class="text-sm font-semibold">데모 계정</p>
                <p class="truncate text-xs text-ink-500">{{ config('mktly.demo.email') }}</p>
            </div>
            <x-glass.button type="button" tint="lavender" size="sm" wire:click="fillDemo">입력하기</x-glass.button>
        </div>
    @endif
</div>
