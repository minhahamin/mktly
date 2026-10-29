<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'title', 'content', 'created_by'])]
class BrandDocument extends Model
{
    use BelongsToWorkspace;

    public function chunks(): HasMany
    {
        return $this->hasMany(BrandChunk::class)->orderBy('position');
    }
}
