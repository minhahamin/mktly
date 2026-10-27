<?php

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

afterEach(function () {
    putenv('MKTLY_ADMIN_PASSWORD');
});

// ---- mktly:create-admin -------------------------------------------------------

it('환경변수의 비밀번호로 관리자와 워크스페이스를 만든다', function () {
    putenv('MKTLY_ADMIN_PASSWORD=very-secure-pass-1');

    $this->artisan('mktly:create-admin', ['--email' => 'owner@example.test', '--name' => '대표', '--workspace' => '우리 가게', '--no-interaction' => true])
        ->expectsOutputToContain('owner@example.test')->assertSuccessful();

    $user = User::where('email', 'owner@example.test')->firstOrFail();
    $workspace = Workspace::where('name', '우리 가게')->firstOrFail();

    expect(Hash::check('very-secure-pass-1', $user->password))->toBeTrue()
        ->and($user->password)->not->toBe('very-secure-pass-1')
        ->and($workspace->users()->where('users.id', $user->id)->first()->pivot->role)->toBe('admin');
});

it('비밀번호가 짧거나 이메일이 잘못되면 만들지 않는다', function () {
    putenv('MKTLY_ADMIN_PASSWORD=short');
    $this->artisan('mktly:create-admin', ['--email' => 'a@example.test', '--no-interaction' => true])
        ->expectsOutputToContain('12자 이상')->assertFailed();

    putenv('MKTLY_ADMIN_PASSWORD=very-secure-pass-1');
    $this->artisan('mktly:create-admin', ['--email' => 'not-an-email', '--no-interaction' => true])->assertFailed();

    expect(User::count())->toBe(0)->and(Workspace::count())->toBe(0);
});

it('이미 있는 이메일이면 실패하고 아무것도 바꾸지 않는다', function () {
    putenv('MKTLY_ADMIN_PASSWORD=very-secure-pass-1');
    User::factory()->create(['email' => 'dup@example.test']);

    $this->artisan('mktly:create-admin', ['--email' => 'dup@example.test', '--no-interaction' => true])
        ->expectsOutputToContain('이미 사용 중')->assertFailed();

    expect(Workspace::count())->toBe(0);
});

it('비대화형으로 실행하면서 비밀번호가 없으면 실패한다', function () {
    $this->artisan('mktly:create-admin', ['--email' => 'a@example.test', '--no-interaction' => true])->assertFailed();

    expect(User::count())->toBe(0);
});

// ---- mktly:bootstrap ------------------------------------------------------------

it('사용자가 없고 환경변수가 있으면 첫 관리자를 만든다', function () {
    config(['mktly.admin' => ['name' => '대표', 'email' => 'boot@example.test', 'password' => 'bootstrap-pass-12', 'workspace' => '부트스트랩 스토어']]);

    $this->artisan('mktly:bootstrap')->assertSuccessful();

    expect(User::where('email', 'boot@example.test')->exists())->toBeTrue()
        ->and(Workspace::where('name', '부트스트랩 스토어')->exists())->toBeTrue();
});

it('여러 번 실행해도 계정을 중복으로 만들지 않는다', function () {
    config(['mktly.admin' => ['name' => '대표', 'email' => 'boot@example.test', 'password' => 'bootstrap-pass-12', 'workspace' => '스토어']]);

    $this->artisan('mktly:bootstrap')->assertSuccessful();
    $this->artisan('mktly:bootstrap')->expectsOutputToContain('이미 사용자가 있어')->assertSuccessful();

    expect(User::count())->toBe(1)->and(Workspace::count())->toBe(1);
});

it('이미 사용자가 있으면 환경변수가 있어도 새 관리자를 만들지 않는다', function () {
    User::factory()->create();
    config(['mktly.admin' => ['name' => '침입자', 'email' => 'intruder@example.test', 'password' => 'bootstrap-pass-12', 'workspace' => 'x']]);

    $this->artisan('mktly:bootstrap')->assertSuccessful();

    expect(User::where('email', 'intruder@example.test')->exists())->toBeFalse();
});

it('환경변수가 없으면 경고만 하고 정상 종료한다', function () {
    config(['mktly.admin' => ['name' => '대표', 'email' => null, 'password' => null, 'workspace' => 'x']]);

    $this->artisan('mktly:bootstrap')->expectsOutputToContain('설정되지 않아')->assertSuccessful();

    expect(User::count())->toBe(0);
});

it('잘못된 값이어도 배포가 실패하지 않고 비밀번호를 출력하지 않는다', function () {
    config(['mktly.admin' => ['name' => '대표', 'email' => 'boot@example.test', 'password' => 'short-pw', 'workspace' => 'x']]);

    $this->artisan('mktly:bootstrap')->doesntExpectOutputToContain('short-pw')->expectsOutputToContain('초기 관리자 생성 실패')->assertSuccessful();

    expect(User::count())->toBe(0);
});

// ---- mktly:demo -----------------------------------------------------------------

it('데모 명령은 별도 워크스페이스에 읽기 전용 계정과 샘플 데이터를 만든다', function () {
    config(['mktly.demo' => ['email' => 'demo@example.test', 'password' => 'public-demo-pw']]);
    [, $real] = userInWorkspace('admin', '실제 스토어');

    $this->artisan('mktly:demo')->assertSuccessful();

    $demo = User::where('email', 'demo@example.test')->firstOrFail();
    $workspace = Workspace::where('name', 'Mktly 데모 스토어')->firstOrFail();

    expect($workspace->id)->not->toBe($real->id)
        ->and($workspace->users()->where('users.id', $demo->id)->first()->pivot->role)->toBe('viewer')
        ->and(Contact::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count())->toBe(1000)
        // 실제 워크스페이스에는 데모 데이터가 섞이지 않는다.
        ->and(Contact::withoutGlobalScopes()->where('workspace_id', $real->id)->count())->toBe(0)
        ->and(Campaign::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count())->toBeGreaterThan(0);
});

it('데모 계정은 읽기 전용이라 캠페인을 만들 수 없다', function () {
    config(['mktly.demo' => ['email' => 'demo@example.test', 'password' => 'public-demo-pw']]);
    $this->artisan('mktly:demo')->assertSuccessful();
    $demo = User::where('email', 'demo@example.test')->firstOrFail();

    $this->actingAs($demo)->get('/dashboard')->assertOk();
    $this->actingAs($demo)->get('/campaigns/create')->assertForbidden();
    $this->actingAs($demo)->get('/segments/suggest')->assertForbidden();
});

it('운영 환경에서는 --force 없이 실행할 수 없다', function () {
    config(['mktly.demo' => ['email' => 'demo@example.test', 'password' => 'public-demo-pw']]);
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('mktly:demo')->expectsOutputToContain('--force')->assertFailed();
    expect(User::count())->toBe(0);

    $this->artisan('mktly:demo', ['--force' => true])->assertSuccessful();
    expect(User::where('email', 'demo@example.test')->exists())->toBeTrue();
});

it('데모 비밀번호가 없거나 역할이 잘못되었거나 이미 있으면 실패한다', function () {
    config(['mktly.demo' => ['email' => 'demo@example.test', 'password' => null]]);
    $this->artisan('mktly:demo')->expectsOutputToContain('MKTLY_DEMO_PASSWORD')->assertFailed();

    config(['mktly.demo.password' => 'public-demo-pw']);
    $this->artisan('mktly:demo', ['--role' => 'owner'])->assertFailed();

    $this->artisan('mktly:demo')->assertSuccessful();
    $this->artisan('mktly:demo')->expectsOutputToContain('이미 있습니다')->assertFailed();
    expect(User::where('email', 'demo@example.test')->count())->toBe(1);
});

// ---- 시더 ----------------------------------------------------------------------

it('로컬이 아니면 기본 시더가 데모 데이터를 만들지 않는다', function () {
    config(['mktly.demo' => ['email' => 'demo@example.test', 'password' => 'public-demo-pw']]);

    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(0)->and(Contact::withoutGlobalScopes()->count())->toBe(0);
});

it('로컬에서는 기본 시더가 데모 데이터를 만든다', function () {
    config(['mktly.demo' => ['email' => 'demo@example.test', 'password' => 'public-demo-pw']]);
    app()->detectEnvironment(fn () => 'local');

    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'demo@example.test')->exists())->toBeTrue();
});

// ---- mktly:bootstrap 데모 데이터 -------------------------------------------------

it('MKTLY_SEED_DEMO가 켜져 있으면 부팅 시 데모 데이터를 읽기 전용으로 만든다', function () {
    config([
        'mktly.admin' => ['name' => '대표', 'email' => 'boot@example.test', 'password' => 'bootstrap-pass-12', 'workspace' => '실제 스토어'],
        'mktly.demo' => ['email' => 'demo@example.test', 'password' => 'public-demo-pw', 'seed' => true, 'role' => 'viewer'],
    ]);

    $this->artisan('mktly:bootstrap')->expectsOutputToContain('데모 데이터를 만들었습니다')->assertSuccessful();

    $demo = User::where('email', 'demo@example.test')->firstOrFail();
    $workspace = Workspace::where('name', 'Mktly 데모 스토어')->firstOrFail();

    expect($workspace->users()->where('users.id', $demo->id)->first()->pivot->role)->toBe('viewer')
        ->and(Contact::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count())->toBe(1000)
        // 실제 워크스페이스는 비어 있고 데모와 섞이지 않는다.
        ->and(Contact::withoutGlobalScopes()->where('workspace_id', Workspace::where('name', '실제 스토어')->value('id'))->count())->toBe(0);
});

it('부팅을 여러 번 해도 데모 데이터를 중복으로 만들지 않는다', function () {
    config(['mktly.admin' => ['name' => '대표', 'email' => null, 'password' => null, 'workspace' => 'x'],
        'mktly.demo' => ['email' => 'demo@example.test', 'password' => 'public-demo-pw', 'seed' => true, 'role' => 'viewer']]);

    $this->artisan('mktly:bootstrap')->assertSuccessful();
    $this->artisan('mktly:bootstrap')->expectsOutputToContain('데모 계정이 이미 있어')->assertSuccessful();

    expect(Workspace::where('name', 'Mktly 데모 스토어')->count())->toBe(1)->and(User::where('email', 'demo@example.test')->count())->toBe(1);
});

it('MKTLY_SEED_DEMO가 꺼져 있으면 데모 데이터를 만들지 않는다', function () {
    config(['mktly.admin' => ['name' => '대표', 'email' => null, 'password' => null, 'workspace' => 'x'],
        'mktly.demo' => ['email' => 'demo@example.test', 'password' => 'public-demo-pw', 'seed' => false, 'role' => 'viewer']]);

    $this->artisan('mktly:bootstrap')->assertSuccessful();

    expect(User::count())->toBe(0);
});

it('데모 비밀번호가 짧거나 역할이 잘못되면 부팅은 계속하고 만들지 않는다', function () {
    config(['mktly.admin' => ['name' => '대표', 'email' => null, 'password' => null, 'workspace' => 'x'],
        'mktly.demo' => ['email' => 'demo@example.test', 'password' => 'short', 'seed' => true, 'role' => 'viewer']]);
    $this->artisan('mktly:bootstrap')->expectsOutputToContain('데모 데이터 생성 실패')->assertSuccessful();

    config(['mktly.demo' => ['email' => 'demo@example.test', 'password' => 'public-demo-pw', 'seed' => true, 'role' => 'owner']]);
    $this->artisan('mktly:bootstrap')->expectsOutputToContain('데모 데이터 생성 실패')->assertSuccessful();

    expect(User::count())->toBe(0);
});

it('이미 사용자가 있어도 데모 데이터는 요청 시 만들 수 있다', function () {
    User::factory()->create();
    config(['mktly.demo' => ['email' => 'demo@example.test', 'password' => 'public-demo-pw', 'seed' => true, 'role' => 'viewer']]);

    $this->artisan('mktly:bootstrap')->assertSuccessful();

    expect(User::where('email', 'demo@example.test')->exists())->toBeTrue();
});
