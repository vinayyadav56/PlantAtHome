<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only audit of a notification's status transitions + provider replies. */
class DeliveryLog extends Model
{
    use HasUuid;

    protected $table = 'marketing_delivery_logs';

    protected $fillable = [
        'uuid', 'notification_id', 'campaign_id', 'channel', 'status', 'provider',
        'provider_message_id', 'provider_response', 'error', 'occurred_at',
    ];

    protected $casts = [
        'provider_response' => 'array',
        'occurred_at'       => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(MarketingNotification::class, 'notification_id');
    }
}
