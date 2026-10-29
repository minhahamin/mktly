<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['workspace_id', 'brand_document_id', 'position', 'content'])]
class BrandChunk extends Model
{
    use BelongsToWorkspace;

    /** 벡터는 크기가 커서 직렬화(JSON 응답, Livewire 상태)에 실리지 않게 한다. */
    protected $hidden = ['embedding'];

    protected function casts(): array
    {
        return ['embedding' => 'array'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(BrandDocument::class, 'brand_document_id');
    }
}
