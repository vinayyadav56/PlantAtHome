<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One execution of a campaign — materializes notifications and tracks tallies. */
class CampaignRun extends Model
{
    use HasUuid;

    protected $table = 'marketing_campaign_runs';

    protected $fillable = [
        'uuid', 'campaign_id', 'audience_version_id', 'status', 'trigger',
        'total_recipients', 'total_messages', 'counts', 'started_at',
        'finished_at', 'error', 'created_by_uuid',
    ];

    protected $casts = [
        'total_recipients' => 'integer',
        'total_messages'   => 'integer',
        'counts'           => 'array',
        'started_at'       => 'datetime',
        'finished_at'      => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(CampaignJob::class, 'run_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(MarketingNotification::class, 'run_id');
    }
}
