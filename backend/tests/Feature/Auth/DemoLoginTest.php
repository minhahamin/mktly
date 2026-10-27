<?php

use App\Livewire\Auth\Login;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

function makeDemoUser(string $role = 'viewer'): User
{
    $workspace = Workspace::create(['name' => 'Mktly 데모 스토어']);
    $user = User::factory()->create(['email' => 'demo@example.test', 'name' => '데모 마케터']);
    $user->forceFill(['is_demo' => true])->save();
    $workspace->users()->attach($user->id, ['role' => $role]);

    return $user;
}

beforeEach(function () {
    config(['mktly.demo.login' => true, 'mktly.signup.enabled' => true]);
    RateLimiter::clear('demo-login|127.0.0.1');
});

it('데모 계정이 있으면 로그인 화면에 시작하기 버튼이 보인다', function () {
    makeDemoUser();

    $this->get('/login')->assertOk()->assertSee('데모 계정으로 시작하기')->assertSee('로그인 없이');
});

it('버튼을 누르면 비밀번호 없이 데모 계정으로 입장해 대시보드로 이동한다', function () {
    $demo = makeDemoUser();

    Livewire::test(Login::class)->call('startDemo')->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($demo);
});

it('기능이 꺼져 있거나 데모 계정이 없으면 버튼이 없고 입장할 수 없다', function () {
    config(['mktly.demo.login' => false]);
    makeDemoUser();

    Livewire::test(Login::class)->assertDontSee('데모 계정으로 시작하기')->call('startDemo')->assertNotFound();
    $this->assertGuest();

    config(['mktly.demo.login' => true]);
    User::query()->delete();

    Livewire::test(Login::class)->assertDontSee('데모 계정으로 시작하기')->call('startDemo')->assertNotFound();
    $this->assertGuest();
});

it('데모 표시가 없는 일반 계정으로는 입장할 수 없다', function () {
    [$user] = userInWorkspace('viewer');

    Livewire::test(Login::class)->assertDontSee('데모 계정으로 시작하기')->call('startDemo')->assertNotFound();
    $this->assertGuest();
});

it('로컬이 아닌 환경에서는 읽기 전용(viewer)이 아닌 데모 계정으로 입장할 수 없다', function () {
    makeDemoUser('marketer');

    Livewire::test(Login::class)->assertDontSee('데모 계정으로 시작하기')->call('startDemo')->assertNotFound();
    $this->assertGuest();
});

it('로컬 환경에서는 마케터 이상의 데모 계정도 입장할 수 있다', function () {
    app()->detectEnvironment(fn () => 'local');
    $demo = makeDemoUser('admin');

    Livewire::test(Login::class)->call('startDemo')->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($demo);
});

it('분당 20번까지만 입장할 수 있다', function () {
    makeDemoUser();
    $component = Livewire::test(Login::class);

    foreach (range(1, 20) as $_) {
        $component->call('startDemo');
    }
    auth()->logout();
    $component->call('startDemo')->assertHasErrors(['email']);

    $this->assertGuest();
});

it('데모 계정으로 들어가면 안내 배너가 보이고 데이터는 읽기만 할 수 있다', function () {
    $demo = makeDemoUser();

    $this->actingAs($demo)->get('/dashboard')->assertOk()->assertSee('데모 계정')->assertSee('읽기 전용')->assertSee('나만의 워크스페이스 만들기');
    $this->actingAs($demo)->get('/campaigns/create')->assertForbidden();
    $this->actingAs($demo)->get('/segments/suggest')->assertForbidden();
    $this->actingAs($demo)->get('/contacts/import')->assertForbidden();
});

it('일반 사용자에게는 데모 배너가 보이지 않는다', function () {
    [$user] = userInWorkspace();

    $this->actingAs($user)->get('/dashboard')->assertDontSee('데모 계정으로 둘러보는 중');
});

it('데모 계정은 비밀번호를 바꿀 수 없다', function () {
    $demo = makeDemoUser();

    $this->actingAs($demo)->get('/settings')->assertOk()->assertSee('비밀번호를 바꿀 수 없습니다');

    Livewire::actingAs($demo)->test(SettingsIndex::class)
        ->set('currentPassword', 'password')->set('newPassword', 'hijacked-pass-1')->set('newPasswordConfirmation', 'hijacked-pass-1')
        ->call('changePassword')->assertForbidden();

    expect(Hash::check('hijacked-pass-1', $demo->fresh()->password))->toBeFalse();
});

it('데모 배너의 버튼은 로그아웃 후 회원가입 화면으로 보낸다', function () {
    $demo = makeDemoUser();

    $this->actingAs($demo)->withSession(['_token' => 't'])->post('/logout', ['_token' => 't', 'next' => 'register'])->assertRedirect('/register');
    $this->assertGuest();
});

it('로그아웃의 이동 대상은 허용된 값만 받는다', function () {
    [$user] = userInWorkspace();

    $this->actingAs($user)->withSession(['_token' => 't'])->post('/logout', ['_token' => 't', 'next' => 'https://evil.test'])->assertRedirect('/login');

    config(['mktly.signup.enabled' => false]);
    [$other] = userInWorkspace('admin', '다른');
    $this->actingAs($other)->withSession(['_token' => 't'])->post('/logout', ['_token' => 't', 'next' => 'register'])->assertRedirect('/login');
});

it('데모 시더가 만든 계정과 워크스페이스는 표시와 발송 차단이 적용된다', function () {
    app(DemoSeeder::class)->seed('seeded-demo@example.test', 'public-demo-pw', 'viewer');

    $user = User::where('email', 'seeded-demo@example.test')->firstOrFail();

    expect($user->is_demo)->toBeTrue()
        ->and($user->workspaces()->first()->sending_enabled)->toBeFalse();
});

// ---- 부팅 시 데모 계정 보정 ---------------------------------------------------------

it('데모 표시가 없던 기존 데모 계정을 부팅 시 보정한다', function () {
    config([
        'mktly.admin' => ['name' => 'x', 'email' => null, 'password' => null, 'workspace' => 'x'],
        'mktly.demo' => ['email' => 'olddemo@example.test', 'password' => 'public-demo-pw', 'seed' => true, 'role' => 'viewer', 'login' => true],
    ]);
    [, $other] = userInWorkspace();
    $demoWorkspace = Workspace::create(['name' => '옛 데모']);
    $old = User::factory()->create(['email' => 'olddemo@example.test']);
    $demoWorkspace->users()->attach($old->id, ['role' => 'viewer']);

    $this->artisan('mktly:bootstrap')->expectsOutputToContain('데모 표시를 추가')->assertSuccessful();

    expect($old->fresh()->is_demo)->toBeTrue()
        ->and($demoWorkspace->fresh()->sending_enabled)->toBeFalse()
        // 다른 워크스페이스는 건드리지 않는다.
        ->and($other->fresh()->sending_enabled)->toBeTrue();
});
