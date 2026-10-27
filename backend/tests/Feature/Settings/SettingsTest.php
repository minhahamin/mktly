<?php

use App\Livewire\Settings\Index;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    [$this->admin, $this->workspace] = userInWorkspace('admin', '우리 스토어');
});

function addWorkspaceMember($workspace, string $role = 'marketer', string $name = '멤버'): User
{
    $user = User::factory()->create(['name' => $name]);
    $workspace->users()->attach($user->id, ['role' => $role]);

    return $user;
}

// ---- 보기 -------------------------------------------------------------------

it('모든 구성원이 설정 화면에서 멤버 목록을 볼 수 있다', function () {
    $marketer = addWorkspaceMember($this->workspace, 'marketer', '김마케터');

    $this->actingAs($marketer)->get('/settings')->assertOk()->assertSee('김마케터')->assertSee('우리 스토어')->assertSee('내 계정');
});

it('관리자가 아니면 관리 기능이 보이지 않는다', function () {
    $viewer = addWorkspaceMember($this->workspace, 'viewer');

    $this->actingAs($viewer)->get('/settings')->assertOk()->assertDontSee('멤버 추가')->assertDontSee('워크스페이스 이름</label>', false);
    $this->actingAs($this->admin)->get('/settings')->assertSee('멤버 추가');
});

it('다른 워크스페이스의 멤버는 목록에 보이지 않는다', function () {
    [, $other] = userInWorkspace('admin', '남의 스토어');
    addWorkspaceMember($other, 'viewer', '남의멤버');

    $this->actingAs($this->admin)->get('/settings')->assertDontSee('남의멤버')->assertDontSee('남의 스토어');
});

// ---- 워크스페이스 이름 ---------------------------------------------------------

it('관리자는 워크스페이스 이름을 바꾼다', function () {
    Livewire::actingAs($this->admin)->test(Index::class)
        ->set('workspaceName', '새 이름 ')->call('saveWorkspace')->assertHasNoErrors();

    expect($this->workspace->fresh()->name)->toBe('새 이름');
});

it('빈 이름은 저장할 수 없다', function () {
    Livewire::actingAs($this->admin)->test(Index::class)
        ->set('workspaceName', '')->call('saveWorkspace')->assertHasErrors(['workspaceName' => 'required']);

    expect($this->workspace->fresh()->name)->toBe('우리 스토어');
});

it('관리자가 아니면 이름을 바꿀 수 없다', function () {
    $marketer = addWorkspaceMember($this->workspace);

    Livewire::actingAs($marketer)->test(Index::class)->set('workspaceName', '해킹')->call('saveWorkspace')->assertForbidden();

    expect($this->workspace->fresh()->name)->toBe('우리 스토어');
});

// ---- 멤버 추가 ---------------------------------------------------------------

it('멤버를 추가하면 계정과 임시 비밀번호가 만들어지고 로그인할 수 있다', function () {
    $component = Livewire::actingAs($this->admin)->test(Index::class)
        ->set('memberName', '신규 멤버')->set('memberEmail', 'new@example.test')->set('memberRole', 'viewer')
        ->call('addMember')->assertHasNoErrors();

    $credentials = $component->get('newCredentials');
    $user = User::where('email', 'new@example.test')->firstOrFail();

    expect($credentials['email'])->toBe('new@example.test')->and(strlen($credentials['password']))->toBe(14)
        ->and(Hash::check($credentials['password'], $user->password))->toBeTrue()
        ->and($this->workspace->users()->where('users.id', $user->id)->first()->pivot->role)->toBe('viewer');

    // 임시 비밀번호는 평문으로 저장되지 않고, 닫으면 화면 상태에서도 사라진다.
    expect($user->password)->not->toBe($credentials['password']);
    $component->call('dismissCredentials')->assertSet('newCredentials', null)->assertSet('memberEmail', '');
});

it('이미 가입된 이메일이나 잘못된 입력은 거부한다', function () {
    Livewire::actingAs($this->admin)->test(Index::class)
        ->set('memberName', '중복')->set('memberEmail', $this->admin->email)->call('addMember')->assertHasErrors(['memberEmail' => 'unique'])
        ->set('memberEmail', 'not-an-email')->call('addMember')->assertHasErrors(['memberEmail'])
        ->set('memberName', '')->set('memberEmail', 'ok@example.test')->call('addMember')->assertHasErrors(['memberName'])
        ->set('memberName', '이름')->set('memberRole', 'owner')->call('addMember')->assertHasErrors(['memberRole']);

    expect(User::where('email', 'ok@example.test')->exists())->toBeFalse();
});

it('관리자가 아니면 멤버를 추가할 수 없다', function () {
    $marketer = addWorkspaceMember($this->workspace);

    Livewire::actingAs($marketer)->test(Index::class)
        ->set('memberName', '침입자')->set('memberEmail', 'intruder@example.test')->call('addMember')->assertForbidden();

    expect(User::where('email', 'intruder@example.test')->exists())->toBeFalse();
});

// ---- 역할 변경/제거 -------------------------------------------------------------

it('관리자는 다른 멤버의 역할을 바꾼다', function () {
    $member = addWorkspaceMember($this->workspace, 'viewer');

    Livewire::actingAs($this->admin)->test(Index::class)->call('changeRole', $member->id, 'marketer');

    expect($this->workspace->users()->where('users.id', $member->id)->first()->pivot->role)->toBe('marketer');
});

it('본인 역할은 바꿀 수 없고 알 수 없는 역할은 거부한다', function () {
    $member = addWorkspaceMember($this->workspace);

    Livewire::actingAs($this->admin)->test(Index::class)->call('changeRole', $this->admin->id, 'viewer')->assertForbidden();
    Livewire::actingAs($this->admin)->test(Index::class)->call('changeRole', $member->id, 'superuser')->assertStatus(422);

    expect($this->workspace->users()->where('users.id', $this->admin->id)->first()->pivot->role)->toBe('admin');
});

it('다른 워크스페이스 사용자의 역할을 바꾸거나 제거할 수 없다', function () {
    [$foreignAdmin, $other] = userInWorkspace('admin', '남의 스토어');
    $foreign = addWorkspaceMember($other, 'viewer');

    Livewire::actingAs($this->admin)->test(Index::class)->call('changeRole', $foreign->id, 'admin')->assertNotFound();
    Livewire::actingAs($this->admin)->test(Index::class)->call('confirmRemove', $foreign->id)->assertNotFound();
    Livewire::actingAs($this->admin)->test(Index::class)->set('removingId', $foreign->id)->call('removeMember')->assertNotFound();

    expect($other->users()->where('users.id', $foreign->id)->first()->pivot->role)->toBe('viewer');
});

it('멤버를 제거하면 워크스페이스에서만 빠지고 계정은 남는다', function () {
    $member = addWorkspaceMember($this->workspace);

    Livewire::actingAs($this->admin)->test(Index::class)
        ->call('confirmRemove', $member->id)->assertSet('removingId', $member->id)
        ->call('removeMember')->assertSet('removingId', null);

    expect($this->workspace->users()->where('users.id', $member->id)->exists())->toBeFalse()
        ->and(User::find($member->id))->not->toBeNull();
});

it('본인은 제거할 수 없다', function () {
    Livewire::actingAs($this->admin)->test(Index::class)->call('confirmRemove', $this->admin->id)->assertForbidden();

    expect($this->workspace->users()->count())->toBe(1);
});

it('관리자가 아니면 멤버를 제거할 수 없다', function () {
    $marketer = addWorkspaceMember($this->workspace);
    $other = addWorkspaceMember($this->workspace, 'viewer');

    Livewire::actingAs($marketer)->test(Index::class)->call('confirmRemove', $other->id)->assertForbidden();
});

it('제거된 멤버는 더 이상 데이터를 보지 못한다', function () {
    $member = addWorkspaceMember($this->workspace, 'marketer');
    $this->workspace->users()->detach($member->id);

    $this->actingAs($member)->get('/campaigns/create')->assertForbidden();
});

// ---- 비밀번호 변경 ---------------------------------------------------------------

it('현재 비밀번호를 확인하고 새 비밀번호로 바꾼다', function () {
    $user = User::factory()->create(['password' => 'old-pass-123']);
    $this->workspace->users()->attach($user->id, ['role' => 'viewer']);

    Livewire::actingAs($user)->test(Index::class)
        ->set('currentPassword', 'old-pass-123')->set('newPassword', 'new-pass-456')->set('newPasswordConfirmation', 'new-pass-456')
        ->call('changePassword')->assertHasNoErrors()->assertSet('currentPassword', '')->assertSet('newPassword', '');

    expect(Hash::check('new-pass-456', $user->fresh()->password))->toBeTrue();

    Auth::logout();
    expect(Auth::attempt(['email' => $user->email, 'password' => 'new-pass-456']))->toBeTrue();
});

it('비밀번호 변경 입력을 검증한다', function () {
    $user = User::factory()->create(['password' => 'old-pass-123']);
    $this->workspace->users()->attach($user->id, ['role' => 'viewer']);
    $component = fn () => Livewire::actingAs($user)->test(Index::class);

    $component()->set('currentPassword', 'wrong')->set('newPassword', 'new-pass-456')->set('newPasswordConfirmation', 'new-pass-456')
        ->call('changePassword')->assertHasErrors(['currentPassword']);
    $component()->set('currentPassword', 'old-pass-123')->set('newPassword', 'short1')->set('newPasswordConfirmation', 'short1')
        ->call('changePassword')->assertHasErrors(['newPassword']);
    $component()->set('currentPassword', 'old-pass-123')->set('newPassword', 'onlyletters')->set('newPasswordConfirmation', 'onlyletters')
        ->call('changePassword')->assertHasErrors(['newPassword']);
    $component()->set('currentPassword', 'old-pass-123')->set('newPassword', 'new-pass-456')->set('newPasswordConfirmation', 'different-789')
        ->call('changePassword')->assertHasErrors(['newPassword']);
    $component()->set('currentPassword', 'old-pass-123')->set('newPassword', 'old-pass-123')->set('newPasswordConfirmation', 'old-pass-123')
        ->call('changePassword')->assertHasErrors(['newPassword']);

    expect(Hash::check('old-pass-123', $user->fresh()->password))->toBeTrue();
});
