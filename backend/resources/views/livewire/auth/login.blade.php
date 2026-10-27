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

        @if ($this->demoAvailable)
            <div class="my-6 flex items-center gap-3 text-xs text-ink-500" aria-hidden="true">
                <span class="h-px flex-1 bg-line"></span>또는<span class="h-px flex-1 bg-line"></span>
            </div>

            <x-glass.button type="button" tint="lavender" size="lg" icon="eye" class="w-full" wire:click="startDemo" wire:loading.attr="disabled" wire:target="startDemo">
                <span wire:loading.remove wire:target="startDemo">데모 계정으로 시작하기</span>
                <span wire:loading wire:target="startDemo">입장 중...</span>
            </x-glass.button>
            <p class="mt-2 text-center text-xs text-ink-500">가입이나 로그인 없이 샘플 데이터를 읽기 전용으로 둘러볼 수 있어요.</p>
        @endif
    </x-glass.card>

    @if ($this->signupEnabled)
        <p class="mt-5 text-center text-sm text-ink-700">
            아직 계정이 없나요? <a href="/register" class="focus-ring rounded font-semibold text-pink-700 hover:underline">회원가입</a>
        </p>
    @endif
</div>
