<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'user_id', 'type', 'input', 'output', 'mock'])]
class AiGeneration extends Model
{
    use BelongsToWorkspace;

    public const TYPE_COPY = 'copy';

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'mock' => 'boolean',
        ];
    }
}
