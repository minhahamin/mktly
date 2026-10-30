<?php

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\ResetPassword;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    RateLimiter::clear('forgot-password|127.0.0.1');
    RateLimiter::clear('reset-password|127.0.0.1');
});

function pwResetRequest(string $email)
{
    return Livewire::test(ForgotPassword::class)->set('email', $email)->call('send');
}

// ---- 링크 요청 --------------------------------------------------------------------

it('로그인 화면에서 비밀번호 찾기로 갈 수 있다', function () {
    $this->get('/login')->assertOk()->assertSee('비밀번호를 잊으셨나요?')->assertSee('/forgot-password');
    $this->get('/forgot-password')->assertOk()->assertSee('재설정 링크 보내기');
});

it('가입한 이메일이면 재설정 링크를 한국어 메일로 보낸다', function () {
    Notification::fake();
    $user = User::factory()->create(['name' => '홍민하', 'email' => 'owner@shop.test']);

    pwResetRequest('owner@shop.test')->assertHasNoErrors()->assertSet('sent', true)->assertSee('메일을 확인해 주세요');

    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use ($user) {
        $mail = $notification->toMail($user);
        $html = (string) $mail->render();

        return $mail->subject === '[Mktly] 비밀번호 재설정 안내'
            && str_contains($html, '홍민하님')
            && str_contains($html, url('/reset-password/'.$notification->token).'?email=owner%40shop.test')
            && str_contains($html, '60분');
    });
});

it('가입하지 않은 이메일이어도 같은 안내를 보여 주고 메일은 보내지 않는다', function () {
    Notification::fake();
    User::factory()->create(['email' => 'owner@shop.test']);

    $known = pwResetRequest('owner@shop.test')->html();
    $unknown = pwResetRequest('nobody@shop.test');

    $unknown->assertHasNoErrors()->assertSet('sent', true)->assertSee('가입된 계정이 있으면 재설정 링크를 보냈습니다');
    expect(str_contains($known, '가입된 계정이 있으면 재설정 링크를 보냈습니다'))->toBeTrue();
    Notification::assertSentTimes(ResetPasswordNotification::class, 1);
});

it('공개 데모 계정은 재설정 링크를 보내지 않는다', function () {
    Notification::fake();
    $demo = User::factory()->create(['email' => 'demo@shop.test']);
    $demo->forceFill(['is_demo' => true])->save();

    pwResetRequest('demo@shop.test')->assertSet('sent', true);

    Notification::assertNothingSent();
});

it('메일 서버 오류가 나도 같은 안내를 보여 준다', function () {
    User::factory()->create(['email' => 'owner@shop.test']);
    Password::shouldReceive('sendResetLink')->once()->andThrow(new RuntimeException('smtp down'));

    pwResetRequest('owner@shop.test')->assertHasNoErrors()->assertSet('sent', true);
});

it('이메일 형식을 검증한다', function () {
    Notification::fake();

    pwResetRequest('')->assertHasErrors(['email' => 'required']);
    pwResetRequest('not-an-email')->assertHasErrors(['email' => 'email']);

    Notification::assertNothingSent();
});

it('같은 IP에서 10분에 5번까지만 요청할 수 있다', function () {
    Notification::fake();

    foreach (range(1, 5) as $i) {
        pwResetRequest("user{$i}@shop.test")->assertHasNoErrors();
    }

    pwResetRequest('user6@shop.test')->assertHasErrors('email')->assertSet('sent', false);
});

it('같은 계정은 60초 안에 다시 메일을 받지 않는다', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'owner@shop.test']);

    pwResetRequest('owner@shop.test');
    pwResetRequest('owner@shop.test')->assertSet('sent', true);

    Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
});

it('로그인한 사용자는 비밀번호 찾기 화면에 들어가지 않는다', function () {
    [$user] = userInWorkspace();

    $this->actingAs($user)->get('/forgot-password')->assertRedirect('/dashboard');
});

// ---- 새 비밀번호 설정 ----------------------------------------------------------------

it('메일의 링크로 새 비밀번호를 정하면 로그인할 수 있고 링크는 한 번만 쓸 수 있다', function () {
    $user = User::factory()->create(['email' => 'owner@shop.test', 'remember_token' => 'old-remember-token']);
    $token = Password::createToken($user);

    $this->get('/reset-password/'.$token.'?email=owner%40shop.test')->assertOk()->assertSee('새 비밀번호 설정');

    Livewire::withQueryParams(['email' => 'owner@shop.test'])->test(ResetPassword::class, ['token' => $token])
        ->assertSet('email', 'owner@shop.test')
        ->set('password', 'new-pass-1234')->set('passwordConfirmation', 'new-pass-1234')
        ->call('resetPassword')->assertHasNoErrors()->assertRedirect('/login');

    $user->refresh();
    expect(Hash::check('new-pass-1234', $user->password))->toBeTrue()
        ->and($user->remember_token)->not->toBe('old-remember-token');

    // 같은 링크로는 다시 바꿀 수 없다.
    Livewire::test(ResetPassword::class, ['token' => $token])->set('email', 'owner@shop.test')
        ->set('password', 'other-pass-1234')->set('passwordConfirmation', 'other-pass-1234')
        ->call('resetPassword')->assertHasErrors('email');
    expect(Hash::check('new-pass-1234', $user->fresh()->password))->toBeTrue();
});

it('재설정 후 로그인 화면에 안내를 보여 준다', function () {
    $user = User::factory()->create(['email' => 'owner@shop.test']);
    $token = Password::createToken($user);

    Livewire::test(ResetPassword::class, ['token' => $token])->set('email', 'owner@shop.test')
        ->set('password', 'new-pass-1234')->set('passwordConfirmation', 'new-pass-1234')->call('resetPassword');

    $this->get('/login')->assertSee('비밀번호를 바꿨습니다. 새 비밀번호로 로그인해 주세요.');
});

it('잘못되었거나 다른 계정의 토큰이면 바꾸지 않는다', function () {
    $user = User::factory()->create(['email' => 'owner@shop.test', 'password' => 'old-pass-1234']);
    $other = User::factory()->create(['email' => 'other@shop.test']);
    $otherToken = Password::createToken($other);

    foreach (['wrong-token', $otherToken] as $token) {
        Livewire::test(ResetPassword::class, ['token' => $token])->set('email', 'owner@shop.test')
            ->set('password', 'new-pass-1234')->set('passwordConfirmation', 'new-pass-1234')
            ->call('resetPassword')->assertHasErrors('email')->assertSet('password', '');
    }

    expect(Hash::check('old-pass-1234', $user->fresh()->password))->toBeTrue();
});

it('만료된 링크로는 바꾸지 않는다', function () {
    $user = User::factory()->create(['email' => 'owner@shop.test', 'password' => 'old-pass-1234']);
    $token = Password::createToken($user);
    $this->travel(61)->minutes();

    Livewire::test(ResetPassword::class, ['token' => $token])->set('email', 'owner@shop.test')
        ->set('password', 'new-pass-1234')->set('passwordConfirmation', 'new-pass-1234')
        ->call('resetPassword')->assertHasErrors('email');

    expect(Hash::check('old-pass-1234', $user->fresh()->password))->toBeTrue();
});

it('공개 데모 계정은 토큰이 있어도 바꾸지 않는다', function () {
    $demo = User::factory()->create(['email' => 'demo@shop.test', 'password' => 'demo-pass-1234']);
    $demo->forceFill(['is_demo' => true])->save();
    $token = Password::createToken($demo);

    Livewire::test(ResetPassword::class, ['token' => $token])->set('email', 'demo@shop.test')
        ->set('password', 'new-pass-1234')->set('passwordConfirmation', 'new-pass-1234')
        ->call('resetPassword')->assertHasErrors('email');

    expect(Hash::check('demo-pass-1234', $demo->fresh()->password))->toBeTrue();
});

it('새 비밀번호 규칙을 검증한다', function () {
    $user = User::factory()->create(['email' => 'owner@shop.test']);
    $token = Password::createToken($user);
    $component = Livewire::test(ResetPassword::class, ['token' => $token])->set('email', 'owner@shop.test');

    $component->set('password', 'short1')->set('passwordConfirmation', 'short1')->call('resetPassword')->assertHasErrors(['password' => '비밀번호는 8자 이상이어야 합니다.']);
    $component->set('password', 'onlyletters')->set('passwordConfirmation', 'onlyletters')->call('resetPassword')->assertHasErrors(['password' => '비밀번호에 숫자를 포함해 주세요.']);
    $component->set('password', 'new-pass-1234')->set('passwordConfirmation', 'different-1234')->call('resetPassword')->assertHasErrors(['password' => '비밀번호 확인이 일치하지 않습니다.']);

    expect(Hash::check('new-pass-1234', $user->fresh()->password))->toBeFalse();
});

it('같은 IP에서 10분에 10번까지만 시도할 수 있다', function () {
    User::factory()->create(['email' => 'owner@shop.test']);
    $component = Livewire::test(ResetPassword::class, ['token' => 'guess'])->set('email', 'owner@shop.test');
    // 실패하면 비밀번호 칸을 비우므로 시도마다 다시 입력한다.
    $attempt = fn () => $component->set('password', 'new-pass-1234')->set('passwordConfirmation', 'new-pass-1234')->call('resetPassword');

    foreach (range(1, 10) as $_) {
        $attempt()->assertHasErrors(['email' => '링크가 만료되었거나 올바르지 않습니다. 비밀번호 찾기를 다시 요청해 주세요.']);
    }

    expect($attempt()->errors()->first('email'))->toContain('시도가 너무 많습니다');
});
