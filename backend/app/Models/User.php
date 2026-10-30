<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordNotification;
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

    private string|false|null $roleCache = null;

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
        if ($this->roleCache === null) {
            $id = $this->currentWorkspaceId();
            $this->roleCache = $id ? ($this->workspaces()->where('workspaces.id', $id)->first()?->pivot->role ?? false) : false;
        }

        return $this->roleCache ?: null;
    }

    /** 비밀번호 재설정 링크를 한국어 메일로 보낸다. */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
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
            'is_demo' => 'boolean',
        ];
    }
}
