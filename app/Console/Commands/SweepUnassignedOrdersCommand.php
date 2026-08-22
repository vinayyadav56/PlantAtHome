<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderItem;
use Marvel\Enums\PaymentStatus;

/**
 * Fulfilment safety net for orders that never got a vendor.
 *
 * The sibling sweep (courier:sweep-undispatched) watches SHIPMENTS that were never booked — but a
 * shipment row only exists once an order has been assigned. With auto-assignment opt-in
 * (settings.options.assignment.auto_assign, default OFF), an unassigned order therefore had NO
 * watchdog whatsoever: no vendor, no shipment, nothing to alarm on, and the vendor never sees it
 * because vendor order lists key off order_items.assigned_shop_id. It would simply sit.
 *
 * This closes that hole: a payable, non-cancelled order with no assigned line past the same SLA is
 * a customer waiting on nobody.
 *
 * Alarm ONLY — it never assigns. Choosing the vendor is the operator's decision, which is the whole
 * point of turning auto-assignment off; silently assigning here would reintroduce it through the
 * back door. Deliberately NOT gated on courier being enabled, unlike the shipment sweep: assignment
 * happens whether or not a courier is ever involved (a self-delivery vendor needs assigning too).
 */
class SweepUnassignedOrdersCommand extends Command
{
    protected $signature = 'orders:sweep-unassigned';

    protected $description = 'Alarm on payable orders left without a vendor past the SLA (does not assign).';

    public function handle(): int
    {
        $minutes = max(1, (int) config('shop.undispatched_shipment_alert_minutes', 120));
        $threshold = now()->subMinutes($minutes);

        $stuck = Order::query()
            // Parent orders only: assignment lives on the parent's order_items, and the per-vertical
            // children would otherwise double-count the same unassigned order.
            ->whereNull('parent_id')
            ->where('created_at', '<', $threshold)
            ->whereNotIn('order_status', ['order-cancelled', 'order-refunded', 'order-failed'])
            ->where(function ($q) {
                // Payable = prepaid-settled OR any COD spelling checkout may store.
                $q->where('payment_status', PaymentStatus::SUCCESS)
                    ->orWhereIn('payment_gateway', ['CASH_ON_DELIVERY', 'CASH', 'COD']);
            })
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('order_items')
                    ->whereColumn('order_items.order_id', 'orders.id')
                    ->whereNotNull('order_items.assigned_shop_id');
            })
            ->get(['id', 'tracking_number', 'created_at']);

        if ($stuck->isEmpty()) {
            return self::SUCCESS;
        }

        Log::warning('orders.unassigned.sweep', [
            'count'       => $stuck->count(),
            'sla_minutes' => $minutes,
            'order_ids'   => $stuck->pluck('id')->all(),
        ]);
        $this->warn("{$stuck->count()} order(s) still have no vendor past the {$minutes}m SLA.");

        return self::SUCCESS;
    }
}
