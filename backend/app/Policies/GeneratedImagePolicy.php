<?php

namespace App\Policies;

use App\Models\GeneratedImage;
use App\Models\User;

class GeneratedImagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roleInCurrentWorkspace() !== null;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, GeneratedImage $image): bool
    {
        return $this->canManage($user) && $image->workspace_id === $user->currentWorkspaceId();
    }

    private function canManage(User $user): bool
    {
        return in_array($user->roleInCurrentWorkspace(), ['admin', 'marketer'], true);
    }
}
