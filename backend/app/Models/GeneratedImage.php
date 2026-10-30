<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'user_id', 'ai_generation_id', 'set_uuid', 'title', 'kind', 'slide_index', 'ratio', 'slide_title', 'data', 'width', 'height', 'background_provider'])]
class GeneratedImage extends Model
{
    use BelongsToWorkspace;

    public const KINDS = ['cardnews' => '카드뉴스', 'cover' => '대표 이미지'];

    public const RATIOS = ['1:1' => '1:1 (정사각형)', '4:5' => '4:5 (세로형)', '16:9' => '16:9 (가로형)'];

    /** Gemini 이미지 모델이 그린 배경. 그 외에는 template(서버가 그린 기본 배경)이다. */
    public const BACKGROUND_GEMINI = 'gemini';

    /** 워크스페이스당 보관할 수 있는 이미지 세트 수 */
    public const MAX_SETS = 30;

    public $timestamps = false;

    /** PNG 본문은 크기가 커서 직렬화(JSON, Livewire 상태)에 실리지 않게 한다. */
    protected $hidden = ['data'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** 목록에서는 PNG 본문을 읽지 않는다. */
    public function scopeWithoutData(Builder $query): void
    {
        $query->select(['id', 'workspace_id', 'user_id', 'ai_generation_id', 'set_uuid', 'title', 'kind', 'slide_index', 'ratio', 'slide_title', 'width', 'height', 'background_provider', 'created_at']);
    }
}
