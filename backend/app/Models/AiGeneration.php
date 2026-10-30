<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'user_id', 'type', 'input', 'output', 'provider', 'mock'])]
class AiGeneration extends Model
{
    use BelongsToWorkspace;

    public const TYPE_COPY = 'copy';

    public const TYPE_CARDNEWS = 'cardnews';

    /** LLM을 호출하는 생성 종류. 일일 상한을 함께 센다. */
    public const LLM_TYPES = [self::TYPE_COPY, self::TYPE_CARDNEWS];

    public const PROVIDER_OPENAI = 'openai';

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'mock' => 'boolean',
        ];
    }
}
