<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A batch of notifications = one Send*Job unit of work (batch_size recipients). */
class CampaignJob extends Model
{
    use HasUuid;

    protected $table = 'marketing_campaign_jobs';

    protected $fillable = [
        'uuid', 'run_id', 'campaign_id', 'batch_number', 'channel', 'size',
        'status', 'attempt', 'queued_at', 'started_at', 'finished_at', 'error',
    ];

    protected $casts = [
        'batch_number' => 'integer',
        'size'         => 'integer',
        'attempt'      => 'integer',
        'queued_at'    => 'datetime',
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(CampaignRun::class, 'run_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(MarketingNotification::class, 'batch_id');
    }
}
