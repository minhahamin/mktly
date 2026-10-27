<?php

namespace App\Livewire\Settings;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('설정')]
class Index extends Component
{
    public const ROLES = ['admin' => '관리자', 'marketer' => '마케터', 'viewer' => '뷰어'];

    public string $workspaceName = '';

    public string $memberName = '';

    public string $memberEmail = '';

    public string $memberRole = 'marketer';

    /** 방금 만든 멤버의 임시 비밀번호. 한 번만 보여주고 닫으면 사라진다. */
    public ?array $newCredentials = null;

    public ?int $removingId = null;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public function mount(): void
    {
        $this->authorize('view', $this->workspace());
        $this->workspaceName = $this->workspace()->name;
    }

    public function saveWorkspace(): void
    {
        $this->authorize('manage', $this->workspace());

        $this->validate(
            ['workspaceName' => ['required', 'string', 'max:100']],
            ['workspaceName.required' => '워크스페이스 이름을 입력해 주세요.', 'workspaceName.max' => '이름은 100자 이하로 입력해 주세요.'],
        );

        $this->workspace()->update(['name' => trim($this->workspaceName)]);
        $this->dispatch('notify', message: '워크스페이스 이름을 저장했습니다.');
    }

    /** 새 멤버 계정을 만들고 임시 비밀번호를 돌려준다. 이미 가입된 이메일은 추가하지 않는다. */
    public function addMember(): void
    {
        $this->authorize('manage', $this->workspace());

        $this->validate([
            'memberName' => ['required', 'string', 'max:100'],
            'memberEmail' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'memberRole' => ['required', Rule::in(array_keys(self::ROLES))],
        ], [
            'memberName.required' => '이름을 입력해 주세요.',
            'memberEmail.required' => '이메일을 입력해 주세요.',
            'memberEmail.email' => '올바른 이메일 형식이 아닙니다.',
            'memberEmail.unique' => '이미 사용 중인 이메일입니다.',
            'memberRole.in' => '올바른 역할을 선택해 주세요.',
        ]);

        $password = Str::password(14, symbols: false);

        DB::transaction(function () use ($password) {
            $user = User::create(['name' => trim($this->memberName), 'email' => $this->memberEmail, 'password' => $password]);
            $this->workspace()->users()->attach($user->id, ['role' => $this->memberRole]);
        });

        $this->newCredentials = ['email' => $this->memberEmail, 'password' => $password];
        $this->reset(['memberName', 'memberEmail']);
        $this->memberRole = 'marketer';
        unset($this->members);
    }

    public function dismissCredentials(): void
    {
        $this->newCredentials = null;
    }

    public function changeRole(int $userId, string $role): void
    {
        $this->authorize('manage', $this->workspace());
        abort_unless(array_key_exists($role, self::ROLES), 422);

        $member = $this->member($userId);
        abort_if($member->id === auth()->id(), 403, '본인의 역할은 바꿀 수 없습니다.');

        $this->workspace()->users()->updateExistingPivot($member->id, ['role' => $role]);
        unset($this->members);
        $this->dispatch('notify', message: "{$member->name}님의 역할을 변경했습니다.");
    }

    public function confirmRemove(int $userId): void
    {
        $this->authorize('manage', $this->workspace());
        $member = $this->member($userId);
        abort_if($member->id === auth()->id(), 403, '본인은 제거할 수 없습니다.');

        $this->removingId = $member->id;
    }

    public function cancelRemove(): void
    {
        $this->removingId = null;
    }

    public function removeMember(): void
    {
        $this->authorize('manage', $this->workspace());
        $member = $this->member((int) $this->removingId);
        abort_if($member->id === auth()->id(), 403, '본인은 제거할 수 없습니다.');

        $this->workspace()->users()->detach($member->id);

        $this->removingId = null;
        unset($this->members);
        $this->dispatch('notify', message: "{$member->name}님을 워크스페이스에서 제거했습니다.");
    }

    public function changePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'current_password'],
            'newPassword' => ['required', Password::min(8)->letters()->numbers(), 'confirmed:newPasswordConfirmation', 'different:currentPassword'],
        ], [
            'currentPassword.required' => '현재 비밀번호를 입력해 주세요.',
            'currentPassword.current_password' => '현재 비밀번호가 올바르지 않습니다.',
            'newPassword.required' => '새 비밀번호를 입력해 주세요.',
            'newPassword.min' => '비밀번호는 8자 이상이어야 합니다.',
            'newPassword.letters' => '비밀번호에 영문자를 포함해 주세요.',
            'newPassword.numbers' => '비밀번호에 숫자를 포함해 주세요.',
            'newPassword.confirmed' => '새 비밀번호 확인이 일치하지 않습니다.',
            'newPassword.different' => '현재와 다른 비밀번호를 입력해 주세요.',
        ]);

        auth()->user()->update(['password' => $this->newPassword]);
        $this->reset(['currentPassword', 'newPassword', 'newPasswordConfirmation']);
        $this->dispatch('notify', message: '비밀번호를 변경했습니다.');
    }

    #[Computed]
    public function members()
    {
        return $this->workspace()->users()->orderBy('name')->get();
    }

    #[Computed]
    public function isAdmin(): bool
    {
        return auth()->user()->can('manage', $this->workspace());
    }

    public function render()
    {
        return view('livewire.settings.index');
    }

    private function workspace(): Workspace
    {
        return Workspace::findOrFail(auth()->user()->currentWorkspaceId());
    }

    /** 현재 워크스페이스의 멤버만 조회한다. 다른 워크스페이스 사용자 id를 넣으면 404다. */
    private function member(int $userId): User
    {
        return $this->workspace()->users()->where('users.id', $userId)->firstOrFail();
    }
}
