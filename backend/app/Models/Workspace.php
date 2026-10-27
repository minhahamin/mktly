<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name'])]
class Workspace extends Model
{
    protected function casts(): array
    {
        return [
            'churn_scored_at' => 'datetime',
            'churn_meta' => 'array',
            'sending_enabled' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }
}
