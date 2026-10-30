<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::guest')]
#[Title('비밀번호 재설정')]
class ResetPassword extends Component
{
    #[Locked]
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    public function resetPassword()
    {
        $this->resetErrorBag();

        // 토큰을 무작위로 대입하는 시도를 막는다. 토큰 자체도 추측할 수 없는 64자 값이다.
        $key = 'reset-password|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            $this->addError('email', "시도가 너무 많습니다. {$minutes}분 후 다시 시도해 주세요.");

            return null;
        }

        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', PasswordRule::min(8)->letters()->numbers(), 'confirmed:passwordConfirmation'],
        ], [
            'email.required' => '이메일을 입력해 주세요.',
            'email.email' => '올바른 이메일 형식이 아닙니다.',
            'password.required' => '새 비밀번호를 입력해 주세요.',
            'password.min' => '비밀번호는 8자 이상이어야 합니다.',
            'password.letters' => '비밀번호에 영문자를 포함해 주세요.',
            'password.numbers' => '비밀번호에 숫자를 포함해 주세요.',
            'password.confirmed' => '비밀번호 확인이 일치하지 않습니다.',
        ]);

        RateLimiter::hit($key, 600);

        $status = User::query()->where('email', $this->email)->where('is_demo', true)->exists()
            ? Password::INVALID_TOKEN
            : Password::reset(
                ['email' => $this->email, 'password' => $this->password, 'token' => $this->token],
                function (User $user, string $password) {
                    // 로그인 유지 토큰을 바꿔 다른 기기의 자동 로그인을 끊는다.
                    $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                    event(new PasswordReset($user));
                },
            );

        if ($status !== Password::PASSWORD_RESET) {
            $this->reset('password', 'passwordConfirmation');
            $this->addError('email', '링크가 만료되었거나 올바르지 않습니다. 비밀번호 찾기를 다시 요청해 주세요.');

            return null;
        }

        RateLimiter::clear($key);
        session()->flash('status', '비밀번호를 바꿨습니다. 새 비밀번호로 로그인해 주세요.');

        return $this->redirect('/login', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.reset-password');
    }
}
