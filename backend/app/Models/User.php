<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    private int|false|null $workspaceIdCache = null;

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    public function currentWorkspaceId(): ?int
    {
        if ($this->workspaceIdCache === null) {
            $this->workspaceIdCache = $this->workspaces()->orderBy('workspaces.id')->value('workspaces.id') ?? false;
        }

        return $this->workspaceIdCache ?: null;
    }

    public function roleInCurrentWorkspace(): ?string
    {
        $id = $this->currentWorkspaceId();

        return $id ? $this->workspaces()->where('workspaces.id', $id)->first()?->pivot->role : null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
