<?php

namespace Marvel\Services\TestDataCleanup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders and everything hanging off them.
 *
 * Two schema facts drive this whole class (both verified against the migrations, not assumed):
 *  1. Order uses SoftDeletes, so `$order->delete()` fires ZERO database cascades — the rows
 *     below would silently survive. We delete with the query builder instead.
 *  2. Every table created by the app-side migrations (order_events, partner_console_orders,
 *     partner_webhook_events, …) declares NO foreign key at all, so nothing cleans them up
 *     for us. They are deleted explicitly, children first.
 *
 * Never touches `products` — only the order_product pivot.
 */
class OrdersCleaner implements CleanerContract
{
    public function key(): string { return 'orders'; }

    public function label(): string { return 'Orders'; }

    public function description(): string
    {
        return 'Removes orders and every dependent record: line items, sub-orders, payments, '
             . 'refunds, shipments, courier/partner tracking, order events, coupon usage, '
             . 'ledger entries and order-linked reviews. The product catalog is not touched.';
    }

    public function stats(): array
    {
        if (!Schema::hasTable('orders')) {
            return ['total' => 0];
        }
        return [
            'total'      => DB::table('orders')->count(),
            'paid'       => DB::table('orders')->where('payment_status', 'payment-success')->count(),
            'shipments'  => Schema::hasTable('shipments') ? DB::table('shipments')->count() : 0,
            'marked_test' => TestDataMarker::countFor(\Marvel\Database\Models\Order::class),
        ];
    }

    /**
     * @param array $scope ids? | before? (date) | statuses? | only_marked? | all?
     */
    public function plan(array $scope): CleanupPlan
    {
        $plan = new CleanupPlan($this->key(), $scope);
        if (!Schema::hasTable('orders')) {
            return $plan;
        }

        $q = DB::table('orders')->select('id');
        if (!empty($scope['ids'])) {
            $q->whereIn('id', (array) $scope['ids']);
        } elseif (!empty($scope['only_marked'])) {
            $q->whereIn('id', TestDataMarker::idsFor(\Marvel\Database\Models\Order::class));
        } elseif (empty($scope['all'])) {
            return $plan; // no scope ⇒ nothing planned; a wipe must be explicit
        }
        if (!empty($scope['before'])) {
            $q->where('created_at', '<', $scope['before']);
        }
        if (!empty($scope['statuses'])) {
            $q->whereIn('order_status', (array) $scope['statuses']);
        }

        $ids = $q->pluck('id')->all();
        // Sub-orders are separate rows pointing at their parent; a scope that names only the
        // parent must still take its children, or the children become unreachable orphans.
        $childIds = DB::table('orders')->whereIn('parent_id', $ids)->pluck('id')->all();
        $ids = array_values(array_unique(array_merge($ids, $childIds)));
        if (!$ids) {
            return $plan;
        }

        $paid = DB::table('orders')->whereIn('id', $ids)->where('payment_status', 'payment-success')->count();
        if ($paid > 0) {
            $plan->warn("{$paid} of these orders have a SUCCESSFUL PAYMENT — confirm they are test transactions.");
        }

        $shipmentIds = Schema::hasTable('shipments')
            ? DB::table('shipments')->whereIn('order_id', $ids)->pluck('id')->all() : [];
        $itemIds = Schema::hasTable('order_items')
            ? DB::table('order_items')->whereIn('order_id', $ids)->pluck('id')->all() : [];
        $pcoIds = Schema::hasTable('partner_console_orders')
            ? DB::table('partner_console_orders')->whereIn('order_id', $ids)
                ->orWhereIn('shipment_id', $shipmentIds ?: [0])->pluck('id')->all() : [];

        // ── children first, exactly the census order ────────────────────────────────
        if ($pcoIds && Schema::hasTable('partner_webhook_events')) {
            $ids2 = DB::table('partner_webhook_events')
                ->whereIn('partner_console_order_id', $pcoIds)
                ->orWhereIn('shipment_id', $shipmentIds ?: [0])->pluck('id')->all();
            $plan->step('partner_webhook_events', $ids2, 'id', 'partner callback log');
        }
        $plan->step('partner_console_orders', $pcoIds, 'id', 'partner order ledger');

        foreach ([
            ['delivery_quotes', 'order_id'],
            ['delivery_partner_earnings', 'order_id'],
            ['vendor_ledger_entries', 'order_id'],
            ['care_plans', 'order_id'],
            ['coupon_usages', 'order_id'],
            ['order_events', 'order_id'],
            ['order_items', 'order_id'],
            ['shipments', 'order_id'],
            // FK-cascade tables, deleted explicitly too: the cascade only fires on a hard
            // delete of `orders`, and being explicit keeps the snapshot complete for restore.
            ['order_product', 'order_id'],
            ['payment_intents', 'order_id'],
            ['order_wallet_points', 'order_id'],
            ['refunds', 'order_id'],
            ['availabilities', 'order_id'],
            ['reviews', 'order_id'],
        ] as [$table, $col]) {
            if (Schema::hasTable($table)) {
                $rowIds = DB::table($table)->whereIn($col, $ids)->pluck('id')->all();
                $plan->step($table, $rowIds, 'id');
            }
        }

        // ordered_files keys on the tracking number, not the id.
        if (Schema::hasTable('ordered_files')) {
            $tns = DB::table('orders')->whereIn('id', $ids)->pluck('tracking_number')->filter()->all();
            if ($tns) {
                $plan->step('ordered_files', DB::table('ordered_files')->whereIn('tracking_number', $tns)->pluck('id')->all(), 'id');
            }
        }

        $plan->step('orders', $ids, 'id', 'the orders themselves (parents + sub-orders)');
        $plan->warn('Product stock levels are NOT rewound — cancelled/return flows own that.');

        return $plan;
    }
}
