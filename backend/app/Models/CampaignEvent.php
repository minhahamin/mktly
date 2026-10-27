<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['campaign_id', 'contact_id', 'type', 'tracked_link_id', 'created_at'])]
class CampaignEvent extends Model
{
    public $timestamps = false;

    public const OPEN = 'open';

    public const CLICK = 'click';

    public const CONVERSION = 'conversion';

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
