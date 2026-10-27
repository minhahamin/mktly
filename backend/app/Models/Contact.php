<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'email', 'name', 'tags', 'joined_at', 'last_purchase_at', 'total_spent', 'churn_score', 'cluster_id'])]
class Contact extends Model
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'joined_at' => 'date',
            'last_purchase_at' => 'date',
            'churn_score' => 'float',
        ];
    }
}
