<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One partner order — THE central ledger row.
 *
 * origin says where it came from: `console` (test-console book / manual record), `shipment` (a
 * real customer shipment's booking, mirrored here so rebooking a shipment no longer erases the
 * old CRN from history), or `webhook` (a CRN first seen in a partner callback that neither ledger
 * knew — the previously-lost orders).
 *
 * partner_status is the PARTNER's own vocabulary (Porter: open/accepted/live/ended/cancelled/
 * reopened); last_status stays our internal normalized word. Deliberately separate columns —
 * every transition goes through PartnerOrderLifecycle, never a raw write.
 */
class PartnerConsoleOrder extends Model
{
    public $guarded = [];

    protected $casts = [
        'request'                 => 'array',
        'response'                => 'array',
        'latest_tracking_payload' => 'array',
        'simulation_response'     => 'array',
        'last_error_payload'      => 'array',
        'last_tracked_at'         => 'datetime',
        'status_changed_at'       => 'datetime',
        'last_error_at'           => 'datetime',
        'accepted_at'             => 'datetime',
        'live_at'                 => 'datetime',
        'ended_at'                => 'datetime',
        'cancelled_at'            => 'datetime',
        'reopened_at'             => 'datetime',
        'simulation_started_at'   => 'datetime',
        'cod_amount_paise'        => 'integer',
        'simulation_flow_type'    => 'integer',
        'simulation_http_status'  => 'integer',
        'track_failures'          => 'integer',
    ];

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PartnerWebhookEvent::class, 'partner_console_order_id')
            ->orderBy('source_webhook_log_id');
    }
}
