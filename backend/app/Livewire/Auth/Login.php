<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts::guest')]
#[Title('로그인')]
class Login extends Component
{
    #[Validate('required|email', message: [
        'email.required' => '이메일을 입력해 주세요.',
        'email.email' => '올바른 이메일 형식이 아닙니다.',
    ])]
    public string $email = '';

    #[Validate('required', message: '비밀번호를 입력해 주세요.')]
    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate();

        $key = Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            $this->addError('email', "로그인 시도가 너무 많습니다. {$seconds}초 후 다시 시도해 주세요.");

            return;
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($key);
            $this->addError('email', '이메일 또는 비밀번호가 올바르지 않습니다.');
            $this->reset('password');

            return;
        }

        RateLimiter::clear($key);
        session()->regenerate();

        $this->redirectIntended('/dashboard', navigate: true);
    }

    public function fillDemo(): void
    {
        abort_unless(app()->environment('local'), 404);

        $this->email = config('mktly.demo.email');
        $this->password = config('mktly.demo.password');
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
