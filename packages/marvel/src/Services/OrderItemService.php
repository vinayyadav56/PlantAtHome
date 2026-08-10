<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderItem;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Models\VendorProductPrice;

/**
 * The single-customer-order line model (P4). Writes order_items in PARALLEL with the
 * legacy per-vertical child orders (dual-write, never breaks the live order flow), and
 * resolves each line's vendor via the per-item scorer, grouping items into shipments
 * (one per vendor + mode). Nothing customer-facing reads this yet — the cutover (hide
 * sub-orders / stop creating children) flips later behind a flag after reconciliation.
 */
class OrderItemService
{
    public function __construct(private ?ItemAssignmentService $engine = null)
    {
        $this->engine = $engine ?: new ItemAssignmentService();
    }

    /**
     * Stock reservation is gated (env MARKETPLACE_RESERVE_STOCK or
     * settings.options.marketplace.reserve_stock; default OFF) so wiring it into the
     * live order lifecycle is opt-in and instantly reversible.
     */
    public static function reserveEnabled(): bool
    {
        $env = env('MARKETPLACE_RESERVE_STOCK');
        if ($env !== null) {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }
        $settings = Settings::getData();
        return (bool) ($settings?->options['marketplace']['reserve_stock'] ?? false);
    }

    /**
     * Release every still-held reservation on this order (cancel / re-plan). Idempotent.
     * $keepItemIds: items on live-booked (sealed) shipments — their stock stays reserved.
     */
    public function releaseForOrder(Order $order, array $keepItemIds = []): void
    {
        if (!self::reserveEnabled()) {
            return;
        }
        $items = OrderItem::where('order_id', $order->id)
            ->where('reserved_qty', '>', 0)->whereNotNull('vendor_product_price_id')
            ->when($keepItemIds, fn ($q) => $q->whereNotIn('id', $keepItemIds))
            ->get();
        foreach ($items as $item) {
            try {
                VendorProductPrice::releaseStock((int) $item->vendor_product_price_id, (int) $item->reserved_qty);
            } catch (\Throwable $e) {
                // reservation is non-authoritative while flag-gated — swallow
            }
            $item->update(['reserved_qty' => 0]);
        }
    }

    /** Commit every reservation on this order (delivered): decrement real stock. Idempotent. */
    public function commitForOrder(Order $order): void
    {
        if (!self::reserveEnabled()) {
            return;
        }
        $items = OrderItem::where('order_id', $order->id)
            ->where('reserved_qty', '>', 0)->whereNotNull('vendor_product_price_id')->get();
        foreach ($items as $item) {
            $committed = false;
            try {
                $committed = VendorProductPrice::commitStock((int) $item->vendor_product_price_id, (int) $item->reserved_qty);
            } catch (\Throwable $e) {
                // swallow
            }
            // Only drop our handle when the commit actually applied; otherwise keep
            // reserved_qty so a reconcile/release can still recover the held vendor stock.
            $item->update($committed ? ['reserved_qty' => 0, 'item_status' => 'delivered'] : ['item_status' => 'delivered']);
        }
    }

    /**
     * Dual-write the parent order's lines into order_items. Atomic (a single bulk insert
     * inside a transaction/savepoint) and COMPLETENESS-idempotent: it only skips when the
     * stored count already matches, and repairs a previously-partial write — so the
     * backfill's "safe to re-run" contract holds even if an earlier write half-failed.
     */
    public function writeForOrder(Order $order, array $products): void
    {
        $expected = count($products);
        if ($expected === 0) {
            return;
        }
        $existing = OrderItem::where('order_id', $order->id)->count();
        if ($existing === $expected) {
            return; // already complete
        }

        $now = Carbon::now();
        $rows = [];
        foreach ($products as $p) {
            $rows[] = [
                'order_id'            => $order->id,
                'product_id'          => (int) ($p['product_id'] ?? 0),
                'variation_option_id' => isset($p['variation_option_id']) ? ($p['variation_option_id'] ?: null) : null,
                'order_quantity'      => (int) ($p['order_quantity'] ?? 1),
                'unit_price'          => (float) ($p['unit_price'] ?? 0),
                'subtotal'            => (float) ($p['subtotal'] ?? 0),
                'reserved_qty'        => 0,
                'item_status'         => 'pending',
                'assignment_status'   => 'unassigned',
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }

        DB::transaction(function () use ($order, $existing, $rows) {
            if ($existing > 0) {
                OrderItem::where('order_id', $order->id)->delete(); // clear a partial write
            }
            OrderItem::insert($rows); // one atomic statement
        });
    }

    /**
     * Auto-assign every line to its recommended vendor (per-item scorer) and group the
     * lines into shipments (one per shop + mode). Re-runnable: clears prior shipments +
     * resets assignments first. Returns a summary.
     */
    public function assignAndGroup(Order $order): array
    {
        [$city, $pincode] = $this->location($order);
        $order->loadMissing('items');

        // Live-booked shipments are SEALED (see isLiveBooked) — the re-plan must leave them and
        // their items untouched. Remaining items regroup into fresh (unbooked) rows only.
        [$lockedShipmentIds, $lockedItemIds] = $this->lockedFor($order);

        // Release any stock held by a prior plan before re-planning (avoids leaking reservations).
        $this->releaseForOrder($order, $lockedItemIds);

        // Reset prior auto grouping (keep any admin overrides? auto pass re-plans all UNLOCKED).
        Shipment::where('order_id', $order->id)->whereNotIn('id', $lockedShipmentIds)->delete();
        OrderItem::where('order_id', $order->id)->whereNotIn('id', $lockedItemIds)->update([
            'shipment_id' => null,
            'assigned_shop_id' => null,
            'fulfillment_mode' => null,
            'assignment_status' => 'unassigned',
            'item_status' => 'pending',
        ]);
        $order->load('items');

        $shipments = [];   // "shopId:mode" => Shipment
        $assigned = 0;
        $unfilled = 0;
        $locked = count($lockedItemIds);

        foreach ($order->items as $item) {
            if (in_array($item->id, $lockedItemIds, true)) {
                continue; // already on a booked (sealed) vendor shipment
            }
            $best = $this->engine->bestFor(
                (int) $item->product_id,
                $item->variation_option_id ? (int) $item->variation_option_id : null,
                (int) $item->order_quantity,
                $city,
                $pincode
            );
            if (!$best) {
                $unfilled++;
                continue;
            }
            $this->applyAssignment($order, $item, $best, $shipments, 'suggested');
            $assigned++;
        }

        \Marvel\Database\Models\OrderEvent::record($order->id, 'items.assigned', [
            'assigned'  => $assigned,
            'unfilled'  => $unfilled,
            'locked'    => $locked,
            'shipments' => count($shipments),
            'auto'      => true,
        ]);

        return [
            'order_id'  => $order->id,
            'assigned'  => $assigned,
            'unfilled'  => $unfilled,
            'locked'    => $locked,
            'shipments' => count($shipments),
        ];
    }

    /**
     * Admin override: assign specific lines to specific vendors, then regroup. Each entry
     * is { order_item_id, shop_id }. Items left out keep their current assignment.
     */
    public function assignItems(Order $order, array $assignments): array
    {
        [$city, $pincode] = $this->location($order);
        $byItem = [];
        foreach ($assignments as $a) {
            if (isset($a['order_item_id'], $a['shop_id'])) {
                $byItem[(int) $a['order_item_id']] = (int) $a['shop_id'];
            }
        }

        // Items on a live-booked vendor shipment are sealed — reassigning one would silently
        // change what a real courier is already carrying. Cancel the booking first.
        [, $lockedItemIds] = $this->lockedFor($order);

        $applied = [];
        $rejected = []; // {order_item_id, reason} — surfaced so the admin UI never shows a false success

        // One transaction + ONE shared shipments map: a same-vendor multi-item override lands on
        // one shipment row (the old per-item reset created N duplicate rows that only regroup()
        // cleaned up — permanently, if anything failed mid-loop).
        \Illuminate\Support\Facades\DB::transaction(function () use ($order, $byItem, $lockedItemIds, $city, $pincode, &$applied, &$rejected) {
            $shipments = [];
            foreach ($byItem as $itemId => $shopId) {
                $item = OrderItem::where('order_id', $order->id)->find($itemId);
                if (!$item) {
                    $rejected[] = ['order_item_id' => $itemId, 'reason' => 'item not found on this order'];
                    continue;
                }
                if (in_array($itemId, $lockedItemIds, true)) {
                    $rejected[] = ['order_item_id' => $itemId, 'reason' => 'vendor shipment already booked — cancel it first'];
                    continue;
                }
                $candidates = $this->engine->candidatesFor(
                    (int) $item->product_id,
                    $item->variation_option_id ? (int) $item->variation_option_id : null,
                    (int) $item->order_quantity,
                    $city,
                    $pincode
                );
                $pick = collect($candidates)->firstWhere('shop_id', $shopId);
                if (!$pick) {
                    // The chosen vendor can no longer fulfil this line (stock/service-area changed) —
                    // reject explicitly; leave the prior assignment untouched.
                    $rejected[] = ['order_item_id' => $itemId, 'reason' => 'chosen vendor cannot fulfil this line'];
                    continue;
                }
                $item->update(['shipment_id' => null]);
                $this->applyAssignment($order, $item, $pick, $shipments, 'overridden');
                $applied[] = $itemId;
            }

            // Rebuild shipment groupings cleanly from the items' current assignments —
            // only when something actually changed (a fully-rejected request must not
            // churn shipment rows).
            if (count($applied) > 0) {
                $this->regroup($order);
            }
        });

        if (count($applied) > 0) {
            \Marvel\Database\Models\OrderEvent::record($order->id, 'items.assigned', [
                'applied'  => count($applied),
                'rejected' => count($rejected),
                'auto'     => false,
            ]);
        }

        return ['order_id' => $order->id, 'applied' => count($applied), 'rejected' => $rejected];
    }

    /**
     * A live-booked shipment (partner order / AWB exists and it isn't cancelled) is SEALED:
     * its rows and item links must never be deleted, rebuilt or reassigned — doing so orphans
     * the real partner job, loses the AWB, and deafens the status webhook (which resolves by
     * shipment id). Cancelling the booking is the only way to unlock it.
     */
    private static function isLiveBooked(Shipment $s): bool
    {
        return ($s->provider_order_id || $s->awb_number) && $s->status !== 'cancelled';
    }

    /** @return array{0: array<int>, 1: array<int>} [locked shipment ids, item ids on them] */
    private function lockedFor(Order $order): array
    {
        $lockedShipmentIds = Shipment::where('order_id', $order->id)->get()
            ->filter(fn ($s) => self::isLiveBooked($s))
            ->pluck('id')->all();
        $lockedItemIds = $lockedShipmentIds
            ? OrderItem::where('order_id', $order->id)->whereIn('shipment_id', $lockedShipmentIds)->pluck('id')->all()
            : [];
        return [$lockedShipmentIds, $lockedItemIds];
    }

    /** Set an item's assignment + attach it to the matching shipment (create if needed). */
    private function applyAssignment(Order $order, OrderItem $item, array $pick, array &$shipments, string $status): void
    {
        $shopId = (int) $pick['shop_id'];
        $mode = $pick['fulfillment_mode'] ?? 'courier';
        $key = $shopId . ':' . $mode;

        if (!isset($shipments[$key])) {
            $eta = $pick['eta_days'] ?? null;
            $shipments[$key] = Shipment::create([
                'order_id'             => $order->id,
                'shop_id'              => $shopId,
                'fulfillment_mode'     => $mode,
                'status'               => 'pending',
                'eta_days'             => $eta,
                'expected_delivery_at' => $eta !== null ? Carbon::now()->addDays((int) $eta)->toDateString() : null,
                'shipping_cost'        => $pick['shipping_cost'] ?? null,
            ]);
        }
        $shipment = $shipments[$key];

        // Atomically reserve stock against the chosen vendor row (oversell protection).
        // Release any prior reservation first (reassignment). reserveStock returns false
        // when there isn't enough free stock — we still assign, with reserved_qty=0 flagged.
        $vppId = $pick['vendor_product_price_id'] ?? null;
        $reservedQty = 0;
        if (self::reserveEnabled() && $vppId) {
            try {
                if ((int) $item->reserved_qty > 0 && $item->vendor_product_price_id) {
                    VendorProductPrice::releaseStock((int) $item->vendor_product_price_id, (int) $item->reserved_qty);
                }
                $qty = (int) $item->order_quantity;
                if (VendorProductPrice::reserveStock((int) $vppId, $qty)) {
                    $reservedQty = $qty;
                }
            } catch (\Throwable $e) {
                $reservedQty = 0;
            }
        }

        // vendor_price_snapshot = the assigned vendor's RATE (their payout basis).
        // margin_amount = (customer unit price − rate) × qty — the platform take,
        // frozen at assignment so later margin-config changes never rewrite history.
        $vendorRate = isset($pick['vendor_rate']) ? (float) $pick['vendor_rate'] : null;
        $marginAmount = null;
        if ($vendorRate !== null && (float) $item->unit_price > 0) {
            $marginAmount = round(((float) $item->unit_price - $vendorRate) * max(1, (int) $item->order_quantity), 2);
        }
        $update = [
            'assigned_shop_id'        => $shopId,
            'vendor_product_price_id' => $vppId,
            'reserved_qty'            => $reservedQty,
            'fulfillment_mode'        => $mode,
            'eta_days'                => $pick['eta_days'] ?? null,
            'shipment_id'             => $shipment->id,
            'vendor_price_snapshot'   => $vendorRate ?? ($pick['selling_price'] ?? null),
            'assignment_status'       => $status,
            'item_status'             => 'assigned',
        ];
        // Railway/EC2 run migrations in the background after deploy — guard the new
        // columns so assignment never breaks while the schema catches up.
        if (self::supportsMarginSnapshot()) {
            $update['margin_amount'] = $marginAmount;
            $update['margin_percent_snapshot'] = isset($pick['margin_percent']) ? (float) $pick['margin_percent'] : null;
        }
        $item->update($update);
    }

    /**
     * Rebuild shipments from the items' current (assigned_shop_id, mode), dropping empties.
     * Live-booked (sealed) shipments and their item links are never touched — deleting one
     * orphans the real partner job and deafens the status webhook. Remaining items for a vendor
     * whose parcel is already booked regroup into a NEW (unbooked) row: a booked parcel's
     * contents are frozen at booking.
     */
    private function regroup(Order $order): void
    {
        $order->load('items');
        [$lockedShipmentIds, $lockedItemIds] = $this->lockedFor($order);

        // Preserve per-(shop:mode) shipping_cost, lane override (mode) + delivery date across the
        // rebuild — they live only on the shipment, so a naive delete+recreate would null them.
        $prior = [];
        foreach (Shipment::where('order_id', $order->id)->get() as $s) {
            $prior[$s->shop_id . ':' . ($s->fulfillment_mode ?: 'courier')] = [
                'shipping_cost'        => $s->shipping_cost,
                'expected_delivery_at' => $s->expected_delivery_at,
                'mode'                 => $s->mode,
            ];
        }

        Shipment::where('order_id', $order->id)->whereNotIn('id', $lockedShipmentIds)->delete();
        $shipments = [];
        foreach ($order->items as $item) {
            if (in_array($item->id, $lockedItemIds, true)) {
                continue; // stays on its sealed shipment
            }
            if (!$item->assigned_shop_id) {
                $item->update(['shipment_id' => null]);
                continue;
            }
            $mode = $item->fulfillment_mode ?: 'courier';
            $key = $item->assigned_shop_id . ':' . $mode;
            if (!isset($shipments[$key])) {
                $carried = $prior[$key] ?? [];
                $shipments[$key] = Shipment::create([
                    'order_id'             => $order->id,
                    'shop_id'              => (int) $item->assigned_shop_id,
                    'fulfillment_mode'     => $mode,
                    'status'               => 'pending',
                    'eta_days'             => $item->eta_days,
                    'shipping_cost'        => $carried['shipping_cost'] ?? null,
                    'mode'                 => $carried['mode'] ?? null,
                    'expected_delivery_at' => $carried['expected_delivery_at']
                        ?? ($item->eta_days !== null ? Carbon::now()->addDays((int) $item->eta_days)->toDateString() : null),
                ]);
            }
            $item->update(['shipment_id' => $shipments[$key]->id]);
        }
    }

    /** Whether order_items has the margin-snapshot columns yet (deploy-lag guard). */
    private static function supportsMarginSnapshot(): bool
    {
        static $supports = null;
        if ($supports === null) {
            try {
                $supports = \Illuminate\Support\Facades\Schema::hasColumn('order_items', 'margin_amount');
            } catch (\Throwable $e) {
                $supports = false;
            }
        }
        return $supports;
    }

    /** @return array{0: ?string, 1: ?string} [city, pincode] from the order's shipping address. */
    private function location(Order $order): array
    {
        $addr = is_array($order->shipping_address) ? $order->shipping_address : (array) $order->shipping_address;
        $city = $addr['city'] ?? null;
        $pincode = $addr['zip'] ?? ($addr['pincode'] ?? null);
        return [$city ? (string) $city : null, $pincode ? (string) $pincode : null];
    }
}
