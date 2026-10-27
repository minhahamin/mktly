<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    /** 워크스페이스 구성원이면 누구나 이름과 멤버 목록을 볼 수 있다. */
    public function view(User $user, Workspace $workspace): bool
    {
        return $user->currentWorkspaceId() === $workspace->id;
    }

    /** 워크스페이스 이름 변경, 멤버 추가/역할 변경/제거는 관리자만 할 수 있다. */
    public function manage(User $user, Workspace $workspace): bool
    {
        return $this->view($user, $workspace) && $user->roleInCurrentWorkspace() === 'admin';
    }
}
