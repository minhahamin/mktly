<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roleInCurrentWorkspace() !== null;
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Contact $contact): bool
    {
        return $this->canManage($user) && $contact->workspace_id === $user->currentWorkspaceId();
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $this->update($user, $contact);
    }

    public function import(User $user): bool
    {
        return $this->canManage($user);
    }

    private function canManage(User $user): bool
    {
        return in_array($user->roleInCurrentWorkspace(), ['admin', 'marketer'], true);
    }
}
