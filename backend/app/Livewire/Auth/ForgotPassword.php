<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('layouts::guest')]
#[Title('비밀번호 찾기')]
class ForgotPassword extends Component
{
    public string $email = '';

    /** 요청을 받았다는 안내를 보여 줄지. 계정이 있는지와 관계없이 같은 안내를 보여 준다. */
    public bool $sent = false;

    public function send(): void
    {
        $this->validate(['email' => ['required', 'email']], [
            'email.required' => '이메일을 입력해 주세요.',
            'email.email' => '올바른 이메일 형식이 아닙니다.',
        ]);

        // 메일 발송 남용을 막기 위해 같은 IP에서 10분에 5번까지만 요청할 수 있다(계정별 60초 제한은 Password Broker가 한다).
        $key = 'forgot-password|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            $this->addError('email', "요청이 너무 많습니다. {$minutes}분 후 다시 시도해 주세요.");

            return;
        }

        RateLimiter::hit($key, 600);

        $user = User::query()->where('email', $this->email)->first();

        // 공개 데모 계정은 비밀번호가 공개용이라 재설정하지 않는다.
        if ($user !== null && ! $user->is_demo) {
            try {
                Password::sendResetLink(['email' => $user->email]);
            } catch (Throwable $e) {
                // 메일 서버 오류를 화면에 드러내면 가입 여부를 알 수 있으므로 기록만 한다.
                report($e);
            }
        }

        $this->sent = true;
    }

    public function render()
    {
        return view('livewire.auth.forgot-password');
    }
}
