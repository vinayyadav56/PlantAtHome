<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One partner webhook delivery, mirrored from the shipping service's webhook_logs.
 * source_webhook_log_id is unique — re-reading the source log can never duplicate an event.
 */
class PartnerWebhookEvent extends Model
{
    public $guarded = [];

    protected $casts = [
        'payload'         => 'array',
        'signature_valid' => 'boolean',
        'event_ts'        => 'integer',
        'received_at'     => 'datetime',
        'processed_at'    => 'datetime',
    ];
}
