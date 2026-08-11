<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Shipment;
use Marvel\Enums\PaymentStatus;
use Marvel\Services\Courier\CourierService;

/**
 * Fulfilment safety net: alarm on courier shipments that were never dispatched. Auto-booking
 * (BookCourierShipments) books on order confirmation and retries transient failures into
 * failed_jobs; this is the final backstop — if a leg on a payable, non-cancelled order is still
 * unbooked (no provider_order_id / awb) past the SLA, a customer is waiting on nothing and ops
 * needs to know.
 *
 * Alarm ONLY — it does not rebook (that's the listener's job; rebooking here would race it and
 * risk a dispatch loop). No-op when courier is disabled, so the default (courier-off) deployment
 * stays silent instead of flagging every pending shipment. Status recovery for BOOKED shipments
 * lives in the Go shipping-service's reconcile loop, not here.
 */
class SweepUndispatchedShipmentsCommand extends Command
{
    protected $signature = 'courier:sweep-undispatched';

    protected $description = 'Alarm on courier shipments left unbooked past the SLA (does not rebook).';

    public function handle(): int
    {
        if (!app(CourierService::class)->enabled()) {
            return self::SUCCESS; // courier off — no bookings are expected
        }

        $minutes = max(1, (int) config('shop.undispatched_shipment_alert_minutes', 120));
        $threshold = now()->subMinutes($minutes);

        $stuck = Shipment::with('order')
            ->whereNull('provider_order_id')
            ->whereNull('awb_number')
            ->whereNotIn('status', ['cancelled', 'delivered', 'rto'])
            // A self-delivery leg is never courier-booked — alarming on it would
            // page ops forever about a shipment that is working as designed.
            ->courierEligible()
            ->where('created_at', '<', $threshold)
            ->whereHas('order', function ($q) {
                $q->whereNotIn('order_status', ['order-cancelled', 'order-refunded', 'order-failed'])
                    ->where(function ($q2) {
                        // Payable = prepaid-settled OR any COD spelling checkout may store.
                        $q2->where('payment_status', PaymentStatus::SUCCESS)
                            ->orWhereIn('payment_gateway', ['CASH_ON_DELIVERY', 'CASH', 'COD']);
                    });
            })
            ->get();

        if ($stuck->isEmpty()) {
            return self::SUCCESS;
        }

        Log::warning('courier.undispatched.sweep', [
            'count'        => $stuck->count(),
            'sla_minutes'  => $minutes,
            'shipment_ids' => $stuck->pluck('id')->all(),
            'order_ids'    => $stuck->pluck('order_id')->unique()->values()->all(),
        ]);
        $this->warn("{$stuck->count()} courier shipment(s) unbooked past {$minutes}m SLA.");

        return self::SUCCESS;
    }
}
