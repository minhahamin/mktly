<div>
    <x-glass.card strong padding="p-8">
        <h1 class="text-2xl font-bold tracking-tight">비밀번호 찾기</h1>
        <p class="mt-1.5 text-sm text-ink-500">가입한 이메일을 입력하면 비밀번호를 다시 정할 수 있는 링크를 보내 드립니다.</p>

        @if ($sent)
            <div class="mt-8 rounded-2xl bg-lavender-100/70 px-4 py-4 text-sm text-ink-700" role="status">
                <p class="font-semibold">메일을 확인해 주세요</p>
                <p class="mt-1">{{ $email }}(으)로 가입된 계정이 있으면 재설정 링크를 보냈습니다. 링크는 {{ config('auth.passwords.users.expire') }}분 동안 쓸 수 있습니다. 메일이 오지 않으면 스팸함을 확인하거나 잠시 후 다시 요청해 주세요.</p>
            </div>

            <x-glass.button type="button" tint="plain" size="lg" class="mt-5 w-full" wire:click="$set('sent', false)">다른 이메일로 다시 요청</x-glass.button>
        @else
            <form wire:submit="send" class="mt-8 space-y-5" novalidate>
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

                <x-glass.button type="submit" size="lg" class="w-full" wire:loading.attr="disabled" wire:target="send">
                    <span wire:loading.remove wire:target="send">재설정 링크 보내기</span>
                    <span wire:loading wire:target="send">보내는 중...</span>
                </x-glass.button>
            </form>
        @endif
    </x-glass.card>

    <p class="mt-5 text-center text-sm text-ink-700">
        비밀번호가 기억나셨나요? <a href="/login" class="focus-ring rounded font-semibold text-pink-700 hover:underline">로그인</a>
    </p>
</div>
