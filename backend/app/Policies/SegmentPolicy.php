<?php

namespace App\Policies;

use App\Models\Segment;
use App\Models\User;

class SegmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roleInCurrentWorkspace() !== null;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Segment $segment): bool
    {
        return $this->canManage($user) && $segment->workspace_id === $user->currentWorkspaceId();
    }

    public function delete(User $user, Segment $segment): bool
    {
        return $this->update($user, $segment);
    }

    private function canManage(User $user): bool
    {
        return in_array($user->roleInCurrentWorkspace(), ['admin', 'marketer'], true);
    }
}
