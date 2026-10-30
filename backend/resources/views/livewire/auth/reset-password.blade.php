<div>
    <x-glass.card strong padding="p-8">
        <h1 class="text-2xl font-bold tracking-tight">새 비밀번호 설정</h1>
        <p class="mt-1.5 text-sm text-ink-500">앞으로 로그인할 때 쓸 비밀번호를 정해 주세요.</p>

        <form wire:submit="resetPassword" class="mt-8 space-y-4" novalidate>
            <x-glass.input label="이메일" icon="mail" type="email" autocomplete="email" wire:model="email" :error="$errors->first('email')" />
            <x-glass.input label="새 비밀번호 (8자 이상, 영문과 숫자)" icon="lock" type="password" autocomplete="new-password" wire:model="password" :error="$errors->first('password')" autofocus />
            <x-glass.input label="새 비밀번호 확인" icon="lock" type="password" autocomplete="new-password" wire:model="passwordConfirmation" />

            <x-glass.button type="submit" size="lg" class="w-full" wire:loading.attr="disabled" wire:target="resetPassword">
                <span wire:loading.remove wire:target="resetPassword">비밀번호 바꾸기</span>
                <span wire:loading wire:target="resetPassword">바꾸는 중...</span>
            </x-glass.button>
        </form>
    </x-glass.card>

    <p class="mt-5 text-center text-sm text-ink-700">
        링크가 만료되었나요? <a href="/forgot-password" class="focus-ring rounded font-semibold text-pink-700 hover:underline">다시 요청하기</a>
    </p>
</div>
