<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'segment_id', 'name', 'channel', 'subject', 'body', 'status', 'scheduled_at', 'sent_at'])]
class Campaign extends Model
{
    use BelongsToWorkspace;

    /** 벡터는 크기가 커서 직렬화(JSON 응답, Livewire 상태)에 실리지 않게 한다. */
    protected $hidden = ['embedding'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'embedding' => 'array',
        ];
    }

    public const STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'failed'];

    public const CHANNELS = ['email' => '이메일', 'sms' => 'SMS (모의 발송)', 'push' => '푸시 (모의 발송)'];

    /** 내용을 수정하거나 발송을 시작할 수 있는 상태인가 */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled'], true);
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    public function trackedLinks(): HasMany
    {
        return $this->hasMany(TrackedLink::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CampaignMessage::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CampaignEvent::class);
    }
}
