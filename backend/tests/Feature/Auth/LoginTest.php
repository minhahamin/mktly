<?php

use App\Livewire\Auth\Login;
use App\Models\User;
use Livewire\Livewire;

it('로그인 화면을 보여준다', function () {
    $this->get('/login')->assertOk()->assertSee('로그인');
});

it('올바른 계정이면 로그인하고 대시보드로 이동한다', function () {
    $user = User::factory()->create(['password' => 'secret-pass-1']);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'secret-pass-1')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('비밀번호가 틀리면 오류를 보여주고 로그인하지 않는다', function () {
    $user = User::factory()->create(['password' => 'secret-pass-1']);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'wrong')
        ->call('login')
        ->assertHasErrors(['email'])
        ->assertSet('password', '');

    $this->assertGuest();
});

it('이메일과 비밀번호는 필수다', function () {
    Livewire::test(Login::class)
        ->call('login')
        ->assertHasErrors(['email' => 'required', 'password' => 'required']);
});

it('5번 실패하면 로그인을 잠시 막는다', function () {
    $user = User::factory()->create(['password' => 'secret-pass-1']);
    $component = Livewire::test(Login::class)->set('email', $user->email);

    foreach (range(1, 5) as $_) {
        $component->set('password', 'wrong')->call('login');
    }

    $component->set('password', 'secret-pass-1')->call('login')->assertHasErrors(['email']);
    $this->assertGuest();
});

it('로그아웃하면 로그인 화면으로 돌아간다', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['_token' => 'test-token'])
        ->post('/logout', ['_token' => 'test-token'])
        ->assertRedirect('/login');
    $this->assertGuest();
});

it('로그인하지 않으면 대시보드에 접근할 수 없다', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});
