<?php

use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Models\Contact;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    config(['mktly.signup.enabled' => true]);
    RateLimiter::clear('register|127.0.0.1');
});

function fillRegister($component, array $override = [])
{
    $data = $override + [
        'name' => '홍길동', 'email' => 'new@example.test', 'workspaceName' => '', 'password' => 'secret-pass-1', 'passwordConfirmation' => 'secret-pass-1',
    ];

    foreach ($data as $key => $value) {
        $component->set($key, $value);
    }

    return $component;
}

it('회원가입 화면을 보여준다', function () {
    $this->get('/register')->assertOk()->assertSee('Mktly 시작하기')->assertSee('체험 모드');
});

it('가입하면 계정과 새 워크스페이스(관리자)가 만들어지고 바로 로그인된다', function () {
    fillRegister(Livewire::test(Register::class))->call('register')->assertHasNoErrors()->assertRedirect('/dashboard');

    $user = User::where('email', 'new@example.test')->firstOrFail();
    $workspace = $user->workspaces()->firstOrFail();

    expect(Hash::check('secret-pass-1', $user->password))->toBeTrue()
        ->and($user->is_demo)->toBeFalse()
        ->and($workspace->name)->toBe('홍길동의 워크스페이스')
        ->and($workspace->pivot->role)->toBe('admin')
        // 스팸 남용 방지: 새 워크스페이스는 이메일 실제 발송이 꺼진 상태로 시작한다.
        ->and($workspace->sending_enabled)->toBeFalse();

    $this->assertAuthenticatedAs($user);
});

it('워크스페이스 이름을 직접 정할 수 있다', function () {
    fillRegister(Livewire::test(Register::class), ['workspaceName' => '우리 가게'])->call('register')->assertHasNoErrors();

    expect(Workspace::where('name', '우리 가게')->exists())->toBeTrue();
});

it('입력을 검증한다', function () {
    Livewire::test(Register::class)->call('register')->assertHasErrors(['name' => 'required', 'email' => 'required', 'password' => 'required']);

    fillRegister(Livewire::test(Register::class), ['email' => 'not-an-email'])->call('register')->assertHasErrors(['email']);
    fillRegister(Livewire::test(Register::class), ['password' => 'short1', 'passwordConfirmation' => 'short1'])->call('register')->assertHasErrors(['password']);
    fillRegister(Livewire::test(Register::class), ['password' => 'onlyletters', 'passwordConfirmation' => 'onlyletters'])->call('register')->assertHasErrors(['password']);
    fillRegister(Livewire::test(Register::class), ['passwordConfirmation' => 'different-pass-2'])->call('register')->assertHasErrors(['password']);

    expect(User::count())->toBe(0);
});

it('이미 가입된 이메일은 거부한다', function () {
    User::factory()->create(['email' => 'dup@example.test']);

    fillRegister(Livewire::test(Register::class), ['email' => 'dup@example.test'])->call('register')->assertHasErrors(['email' => 'unique']);

    expect(User::count())->toBe(1)->and(Workspace::count())->toBe(0);
});

it('같은 IP에서 시간당 5번까지만 가입할 수 있다', function () {
    foreach (range(1, 5) as $i) {
        auth()->logout();
        fillRegister(Livewire::test(Register::class), ['email' => "user{$i}@example.test"])->call('register')->assertHasNoErrors();
    }

    auth()->logout();
    fillRegister(Livewire::test(Register::class), ['email' => 'user6@example.test'])->call('register')->assertHasErrors(['email']);

    expect(User::where('email', 'user6@example.test')->exists())->toBeFalse();
});

it('회원가입이 꺼져 있으면 404이고 로그인 화면에 링크도 없다', function () {
    config(['mktly.signup.enabled' => false]);

    $this->get('/register')->assertNotFound();
    Livewire::test(Login::class)->assertDontSee('회원가입');
    expect(User::count())->toBe(0);
});

it('로그인 화면에 회원가입 링크가 보인다', function () {
    Livewire::test(Login::class)->assertSee('아직 계정이 없나요?')->assertSee('/register');
});

it('이미 로그인한 사용자는 회원가입 화면에 갈 수 없다', function () {
    [$user] = userInWorkspace();

    $this->actingAs($user)->get('/register')->assertRedirect();
});

it('가입 직후 대시보드에 환영 안내가 나온다', function () {
    [$user] = userInWorkspace();

    $this->actingAs($user)->withSession(['welcome' => true])->get('/dashboard')->assertOk()->assertSee('가입을 환영합니다')->assertSee('체험 모드');

    $this->flushSession();
    $this->actingAs($user)->get('/dashboard')->assertDontSee('가입을 환영합니다');
});

it('가입자는 자기 워크스페이스 데이터만 본다', function () {
    [, $other] = userInWorkspace('admin', '남의 스토어');
    Contact::withoutGlobalScopes()->create(['workspace_id' => $other->id, 'email' => 'secret@example.test', 'name' => '남의고객']);

    fillRegister(Livewire::test(Register::class))->call('register');
    $user = User::where('email', 'new@example.test')->firstOrFail();

    $this->actingAs($user)->get('/contacts')->assertOk()->assertDontSee('남의고객');
});
