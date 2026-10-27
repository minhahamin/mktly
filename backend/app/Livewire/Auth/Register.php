<?php

namespace App\Livewire\Auth;

use App\Services\Setup\AccountRegistrar;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::guest')]
#[Title('회원가입')]
class Register extends Component
{
    public string $name = '';

    public string $email = '';

    public string $workspaceName = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function mount(): void
    {
        abort_unless(config('mktly.signup.enabled'), 404);
    }

    public function register(AccountRegistrar $registrar)
    {
        abort_unless(config('mktly.signup.enabled'), 404);

        // 자동 가입 남용을 막기 위해 같은 IP에서 시간당 5번까지만 가입할 수 있다.
        $key = 'register|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            $this->addError('email', "가입 시도가 너무 많습니다. {$minutes}분 후 다시 시도해 주세요.");

            return null;
        }

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'workspaceName' => ['nullable', 'string', 'max:100'],
            'password' => ['required', Password::min(8)->letters()->numbers(), 'confirmed:passwordConfirmation'],
        ], [
            'name.required' => '이름을 입력해 주세요.',
            'email.required' => '이메일을 입력해 주세요.',
            'email.email' => '올바른 이메일 형식이 아닙니다.',
            'email.unique' => '이미 사용 중인 이메일입니다.',
            'password.required' => '비밀번호를 입력해 주세요.',
            'password.min' => '비밀번호는 8자 이상이어야 합니다.',
            'password.letters' => '비밀번호에 영문자를 포함해 주세요.',
            'password.numbers' => '비밀번호에 숫자를 포함해 주세요.',
            'password.confirmed' => '비밀번호 확인이 일치하지 않습니다.',
        ]);

        RateLimiter::hit($key, 3600);

        $user = $registrar->register($data['name'], $data['email'], $data['password'], (string) ($data['workspaceName'] ?? ''));

        Auth::login($user);
        session()->regenerate();
        session()->flash('welcome', true);

        return $this->redirect('/dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.register');
    }
}
