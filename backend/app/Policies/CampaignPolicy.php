<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roleInCurrentWorkspace() !== null;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    /** 초안/예약 상태에서만 수정하거나 발송할 수 있다. */
    public function update(User $user, Campaign $campaign): bool
    {
        return $this->owns($user, $campaign) && $campaign->isEditable();
    }

    public function send(User $user, Campaign $campaign): bool
    {
        return $this->update($user, $campaign);
    }

    /** 발송 중인 캠페인은 삭제할 수 없다. */
    public function delete(User $user, Campaign $campaign): bool
    {
        return $this->owns($user, $campaign) && $campaign->status !== 'sending';
    }

    private function owns(User $user, Campaign $campaign): bool
    {
        return $this->canManage($user) && $campaign->workspace_id === $user->currentWorkspaceId();
    }

    private function canManage(User $user): bool
    {
        return in_array($user->roleInCurrentWorkspace(), ['admin', 'marketer'], true);
    }
}
