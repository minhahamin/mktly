<div>
    <x-glass.card strong padding="p-8">
        <h1 class="text-2xl font-bold tracking-tight">Mktly 시작하기</h1>
        <p class="mt-1.5 text-sm text-ink-500">계정을 만들면 나만의 워크스페이스가 생성됩니다.</p>

        <form wire:submit="register" class="mt-8 space-y-4" novalidate>
            <x-glass.input label="이름" autocomplete="name" wire:model="name" :error="$errors->first('name')" placeholder="홍길동" maxlength="100" autofocus />
            <x-glass.input label="이메일" icon="mail" type="email" autocomplete="email" wire:model="email" :error="$errors->first('email')" placeholder="you@company.com" maxlength="255" />
            <x-glass.input label="워크스페이스 이름 (선택)" wire:model="workspaceName" :error="$errors->first('workspaceName')" placeholder="예: 우리 가게" maxlength="100" />
            <x-glass.input label="비밀번호 (8자 이상, 영문과 숫자)" icon="lock" type="password" autocomplete="new-password" wire:model="password" :error="$errors->first('password')" />
            <x-glass.input label="비밀번호 확인" icon="lock" type="password" autocomplete="new-password" wire:model="passwordConfirmation" />

            <x-glass.button type="submit" size="lg" class="w-full" wire:loading.attr="disabled" wire:target="register">
                <span wire:loading.remove wire:target="register">회원가입</span>
                <span wire:loading wire:target="register">만드는 중...</span>
            </x-glass.button>
        </form>

        <p class="mt-5 rounded-2xl bg-lavender-100/70 px-4 py-3 text-xs text-ink-700">
            새 워크스페이스는 체험 모드로 시작합니다. 고객, 세그먼트, 캠페인 작성과 AI 기능은 모두 쓸 수 있지만 이메일은 실제로 발송되지 않습니다.
        </p>
    </x-glass.card>

    <p class="mt-5 text-center text-sm text-ink-700">
        이미 계정이 있나요? <a href="/login" class="focus-ring rounded font-semibold text-pink-700 hover:underline">로그인</a>
    </p>
</div>
