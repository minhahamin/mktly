<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['campaign_id', 'token', 'target_url'])]
class TrackedLink extends Model
{
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** 이 링크의 클릭 이벤트 */
    public function clicks(): HasMany
    {
        return $this->hasMany(CampaignEvent::class)->where('type', CampaignEvent::CLICK);
    }
}
