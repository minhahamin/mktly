<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
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

    /** 비밀번호 없이 읽기 전용 데모 계정으로 입장한다. 데모 계정이 없거나 기능이 꺼져 있으면 404다. */
    public function startDemo(): void
    {
        $user = $this->demoUser();
        abort_unless($user, 404);

        $key = 'demo-login|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 20)) {
            $this->addError('email', '잠시 후 다시 시도해 주세요.');

            return;
        }

        RateLimiter::hit($key, 60);

        Auth::login($user);
        session()->regenerate();

        $this->redirect('/dashboard', navigate: true);
    }

    #[Computed]
    public function demoAvailable(): bool
    {
        return $this->demoUser() !== null;
    }

    #[Computed]
    public function signupEnabled(): bool
    {
        return (bool) config('mktly.signup.enabled');
    }

    public function render()
    {
        return view('livewire.auth.login');
    }

    /**
     * 데모 입장에 쓸 계정. 기능이 켜져 있고 DB에 데모 표시(is_demo)된 계정이 있을 때만 돌려준다.
     * 안전장치: 로컬이 아니면 읽기 전용(viewer) 계정만 허용한다. 실수로 마케터 이상이면 누구나 메일 발송과 AI 호출을 할 수 있기 때문이다.
     */
    private function demoUser(): ?User
    {
        if (! config('mktly.demo.login')) {
            return null;
        }

        $user = User::query()->where('is_demo', true)->orderBy('id')->first();

        if ($user === null) {
            return null;
        }

        if (! app()->isLocal() && $user->roleInCurrentWorkspace() !== 'viewer') {
            return null;
        }

        return $user;
    }
}
