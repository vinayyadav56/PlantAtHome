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

    /**
     * Persist whatever rider identity a partner payload happens to carry.
     *
     * Two shapes reach us for the same fact — a webhook says
     * order_details.driver_details.{driver_name,mobile}, a track response says
     * partner_info.{name,mobile} — and every caller was re-deriving that mapping. Written once
     * here so the live track call, the webhook mirror and the reconcile pass agree.
     *
     * Only fields the partner actually reported are written: a later payload that omits the rider
     * must not erase a rider we already know about.
     */
    public function syncDriverFrom(?array $payload): bool
    {
        if (!is_array($payload)) {
            return false;
        }
        $d = $payload['order_details']['driver_details'] ?? $payload['partner_info'] ?? null;
        if (!is_array($d)) {
            return false;
        }
        $fill = array_filter([
            'driver_name'    => $this->firstFilled($d, ['driver_name', 'name', 'partner_name']),
            'driver_phone'   => $this->firstFilled($d, ['mobile', 'phone', 'driver_phone']),
            'vehicle_number' => $this->firstFilled($d, ['vehicle_number', 'vehicle_no']),
        ], fn ($v) => $v !== null);
        if (!$fill) {
            return false;
        }
        $this->forceFill($fill)->save();
        return true;
    }

    /** Porter nests the mobile as {country_code, mobile_number}; flatten before storing. */
    private function firstFilled(array $src, array $keys): ?string
    {
        foreach ($keys as $k) {
            $v = $src[$k] ?? null;
            if (is_array($v)) {
                $v = trim(($v['country_code'] ?? '') . ($v['mobile_number'] ?? ''));
            }
            $v = trim((string) $v);
            if ($v !== '') {
                return $v;
            }
        }
        return null;
    }

    /**
     * The booking as the admin needs it: normalized status + a sparse rider block.
     *
     * Sparse on purpose — Borzo and Shiprocket report a subset of what Porter does, and an empty
     * string rendered as a rider's name is worse than no rider card at all.
     */
    public function toBookingPayload(): array
    {
        $driver = array_filter([
            'name'           => $this->driver_name,
            'phone'          => $this->driver_phone,
            'vehicle_number' => $this->vehicle_number,
        ], fn ($v) => trim((string) $v) !== '');

        return [
            'provider_order_id' => $this->provider_order_id,
            'provider'          => $this->partner_code,
            'flow_type'         => $this->simulation_flow_type,
            'flow_started_at'   => optional($this->simulation_started_at)->toIso8601String(),
            'provider_status'   => $this->partner_status,
            'normalized_status' => $this->last_status,
            'status_changed_at' => optional($this->status_changed_at)->toIso8601String(),
            'driver'            => $driver ?: null,
        ];
    }
}
