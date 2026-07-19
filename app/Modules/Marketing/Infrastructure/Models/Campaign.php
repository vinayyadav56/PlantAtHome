<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A campaign: an audience × channels × templates × schedule. Nothing sends from
 * here — launching creates a CampaignRun and hands materialization to the queue.
 */
class Campaign extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'marketing_campaigns';

    protected $fillable = [
        'uuid', 'name', 'description', 'audience_id', 'audience_version_id',
        'channels', 'status', 'schedule_type', 'scheduled_at', 'cron_expression',
        'timezone', 'batch_size', 'throttle_per_minute', 'send_delay_seconds',
        'priority', 'max_retries', 'last_run_at', 'next_run_at', 'created_by_uuid',
    ];

    protected $casts = [
        'channels'            => 'array',
        'scheduled_at'        => 'datetime',
        'batch_size'          => 'integer',
        'throttle_per_minute' => 'integer',
        'send_delay_seconds'  => 'integer',
        'priority'            => 'integer',
        'max_retries'         => 'integer',
        'last_run_at'         => 'datetime',
        'next_run_at'         => 'datetime',
    ];

    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class, 'audience_id');
    }

    public function audienceVersion(): BelongsTo
    {
        return $this->belongsTo(AudienceVersion::class, 'audience_version_id');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(CampaignTemplate::class, 'campaign_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CampaignRun::class, 'campaign_id')->orderByDesc('id');
    }
}
