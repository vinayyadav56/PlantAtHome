<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One materialized message: a single recipient on a single channel for a run.
 * This is the frozen unit of work — its status walks the delivery lifecycle and
 * every transition also appends a DeliveryLog row.
 */
class MarketingNotification extends Model
{
    use HasUuid;

    protected $table = 'marketing_notifications';

    protected $fillable = [
        'uuid', 'run_id', 'campaign_id', 'batch_id', 'channel', 'recipient',
        'recipient_ref', 'template_id', 'rendered_subject', 'rendered_body',
        'status', 'provider', 'provider_message_id', 'provider_response',
        'retry_count', 'error', 'queued_at', 'sent_at', 'delivered_at', 'read_at',
    ];

    protected $casts = [
        'recipient_ref'     => 'array',
        'provider_response' => 'array',
        'retry_count'       => 'integer',
        'queued_at'         => 'datetime',
        'sent_at'           => 'datetime',
        'delivered_at'      => 'datetime',
        'read_at'           => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(CampaignRun::class, 'run_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CampaignJob::class, 'batch_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DeliveryLog::class, 'notification_id');
    }
}
