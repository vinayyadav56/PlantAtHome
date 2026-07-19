<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Binds one Template to a Campaign for a given channel (one per channel). */
class CampaignTemplate extends Model
{
    use HasUuid;

    protected $table = 'marketing_campaign_templates';

    protected $fillable = ['uuid', 'campaign_id', 'template_id', 'channel'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }
}
