<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderItem;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Models\ShipmentItem;
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
     * Whether a new order assigns itself to vendors automatically.
     *
     * Default OFF: an order now waits for an admin to choose the vendor, because auto-assignment
     * silently picked a supplier (and auto-booking then despatched a courier) before anyone could
     * intervene. The manual path already exists end to end — POST orders/{id}/auto-assign-items
     * runs THIS same service on demand, so turning the flag off removes the automation, not the
     * capability.
     *
     * env MARKETPLACE_AUTO_ASSIGN or settings.options.assignment.auto_assign, matching
     * reserveEnabled() above and every other operational toggle in this codebase.
     */
    public static function autoAssignEnabled(): bool
    {
        $env = env('MARKETPLACE_AUTO_ASSIGN');
        if ($env !== null) {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }
        $settings = Settings::getData();
        return (bool) ($settings?->options['assignment']['auto_assign'] ?? false);
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
        $this->queueProjectionRefresh($items);
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
        $this->queueProjectionRefresh($items);
    }

    /**
     * Stock moved (reserve/release/commit) — refresh the city-availability
     * projection for the touched products so the storefront's in-stock gate
     * tracks orders instead of waiting for the 03:30 rebuild. Queued + deduped
     * (ShouldBeUnique per product); must never fail the order flow.
     */
    private function queueProjectionRefresh($items): void
    {
        try {
            foreach (collect($items)->pluck('product_id')->filter()->unique() as $productId) {
                \Marvel\Jobs\RecomputeProductAvailabilityJob::dispatch((int) $productId);
            }
        } catch (\Throwable $e) {
            // projection is a cache — the daily rebuild repairs any miss
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
        $droppedShipmentIds = Shipment::where('order_id', $order->id)
            ->whereNotIn('id', $lockedShipmentIds ?: [0])->pluck('id')->all();
        if ($droppedShipmentIds && self::supportsAllocations()) {
            // Allocations do not cascade — this table carries no FK (see its migration),
            // and an orphan row would keep a deleted parcel's units out of the re-plan.
            //
            // But NOT a locked line's rows: lockedFor() locks a line as a WHOLE when any of
            // its units sit on a booked parcel, and the loop below then skips it. Deleting its
            // rows here and skipping it there destroyed paid-for units with no cancellation.
            ShipmentItem::whereIn('shipment_id', $droppedShipmentIds)
                ->whereNotIn('order_item_id', $lockedItemIds ?: [0])
                ->delete();

            // Any parcel still holding rows is carrying a locked line's free units, so it is
            // no longer droppable.
            $stillUsed = ShipmentItem::whereIn('shipment_id', $droppedShipmentIds)
                ->pluck('shipment_id')->unique()->map(fn ($id) => (int) $id)->all();
            $droppedShipmentIds = array_values(array_diff($droppedShipmentIds, $stillUsed));
        }
        Shipment::whereIn('id', $droppedShipmentIds ?: [0])->delete();
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

        // order_items.shipment_id is DERIVED (see syncItemShipmentColumn). This path never
        // refreshed it, so a line skipped as locked above kept whatever it had — stale the
        // moment its sibling parcels were dropped.
        self::syncItemShipmentColumn($order);

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
    /**
     * Keep the ORDER-level vendor honest after a per-line change.
     *
     * The assignment panel and the per-item table are two views of one
     * decision, so they have to agree in BOTH directions: approving a vendor
     * moves the lines (syncOrderVendor), and moving the lines back-fills the
     * order-level column here. Only when every line agrees — a genuinely
     * multi-vendor order has no single order-level vendor, so the column is
     * left as the operator last approved it rather than being given a
     * misleading winner.
     */
    private function syncOrderLevelVendor(Order $order): void
    {
        try {
            $shops = OrderItem::where('order_id', $order->id)
                ->whereNotNull('assigned_shop_id')
                ->distinct()
                ->pluck('assigned_shop_id');
            if ($shops->count() !== 1) {
                return;
            }
            $shopId = (int) $shops->first();
            if ((int) $order->vendor_shop_id === $shopId) {
                return;
            }
            $order->forceFill(['vendor_shop_id' => $shopId])->save();
        } catch (\Throwable $e) {
            // bookkeeping only — never break an assignment over it
        }
    }

    /**
     * Move every line this vendor CAN fulfil onto them — the order-level
     * "approve assignment" action expressed in terms of the lines, which are
     * what shipments (and therefore the vendor shown on each shipment card)
     * are actually built from.
     *
     * Lines already on that vendor are skipped (no churn), lines the vendor
     * cannot fulfil and lines on a live-booked shipment come back in
     * `rejected` with their reason.
     */
    public function syncOrderVendor(Order $order, int $shopId): array
    {
        $assignments = OrderItem::where('order_id', $order->id)
            ->get(['id', 'assigned_shop_id'])
            ->filter(fn ($i) => (int) $i->assigned_shop_id !== $shopId)
            ->map(fn ($i) => ['order_item_id' => (int) $i->id, 'shop_id' => $shopId])
            ->values()
            ->all();

        if (empty($assignments)) {
            return ['order_id' => $order->id, 'applied' => 0, 'rejected' => [], 'already' => true];
        }

        return $this->assignItems($order, $assignments);
    }

    /**
     * Move the given lines of ONE vendor onto their own shipment (or back to
     * the vendor's default parcel when $separate is false).
     *
     * Default grouping — one shipment per vendor — is right for almost every
     * order; this is the escape hatch for the ones that physically can't go in
     * one vehicle. Lines on an already-booked shipment are refused: their
     * parcel is with a courier.
     */
    /**
     * Move SOME UNITS of one or more lines onto a new parcel — the quantity split.
     *
     * splitShipment() above moves whole LINES by stamping a split_group marker and letting
     * regroup() rebuild. That cannot express "3 of these 5 stay, 2 go": split_group is a
     * scalar on the line, so both halves would carry the same marker and land back
     * together. This carves the allocations directly and never calls regroup() — which is
     * exactly why regroup() skips any line holding allocations on more than one shipment.
     *
     * $items: [ ['order_item_id' => int, 'quantity' => int(units to MOVE)], ... ]
     *
     * @return array{order_id:int, applied:int, shipment_id:?int, rejected:array}
     */
    public function splitByQuantity(Order $order, array $items, ?string $reason = null, ?string $notes = null): array
    {
        if (!self::supportsAllocations()) {
            return ['order_id' => $order->id, 'applied' => 0, 'shipment_id' => null, 'rejected' => [
                ['order_item_id' => null, 'reason' => 'quantity split is not available yet on this deployment'],
            ]];
        }

        $applied = [];
        $rejected = [];
        $target = null;

        DB::transaction(function () use ($order, $items, &$applied, &$rejected, &$target) {
            // Serialise concurrent splits on the same order: two operators splitting the
            // same line would otherwise each read the full allocation and both succeed.
            Order::whereKey($order->id)->lockForUpdate()->first();
            [$lockedShipmentIds] = $this->lockedFor($order);

            // ONE new parcel for the whole request, however many lines it names.
            $group = substr((string) time(), -6) . random_int(10, 99);

            foreach ($items as $row) {
                $itemId = (int) ($row['order_item_id'] ?? 0);
                $move = (int) ($row['quantity'] ?? 0);

                $item = OrderItem::where('order_id', $order->id)->lockForUpdate()->find($itemId);
                if (!$item) {
                    $rejected[] = ['order_item_id' => $itemId, 'reason' => 'item not found on this order'];
                    continue;
                }
                if (!$item->assigned_shop_id) {
                    $rejected[] = ['order_item_id' => $itemId, 'reason' => 'assign a vendor before splitting this line'];
                    continue;
                }

                $allocs = ShipmentItem::where('order_item_id', $item->id)->lockForUpdate()->get();
                if ($allocs->isEmpty()) {
                    $rejected[] = ['order_item_id' => $itemId, 'reason' => 'this line is not on a shipment yet'];
                    continue;
                }
                if ($allocs->pluck('shipment_id')->intersect($lockedShipmentIds)->isNotEmpty()) {
                    $rejected[] = ['order_item_id' => $itemId, 'reason' => 'vendor shipment already booked — cancel it first'];
                    continue;
                }

                // Take the units from the largest allocation, so splitting an already-split
                // line keeps carving the parcel that can actually spare them.
                $source = $allocs->sortByDesc('quantity')->first();
                $available = (int) $source->quantity;
                if ($move < 1 || $move >= $available) {
                    $rejected[] = [
                        'order_item_id' => $itemId,
                        'reason'        => "move between 1 and " . max(0, $available - 1) . " unit(s); to move the whole line use the vendor split",
                    ];
                    continue;
                }

                // The parcel is minted from the FIRST accepted line, then every later line in
                // the request joins it. Without this check a request naming two vendors' lines
                // would load them into one parcel that no single courier can collect.
                if ($target !== null
                    && ((int) $target->shop_id !== (int) $item->assigned_shop_id
                        || ($target->fulfillment_mode ?: 'courier') !== ($item->fulfillment_mode ?: 'courier'))) {
                    $rejected[] = [
                        'order_item_id' => $itemId,
                        'reason'        => 'items from a different vendor or delivery lane cannot travel in one parcel',
                    ];
                    continue;
                }

                if ($target === null) {
                    $sourceShipment = Shipment::find($source->shipment_id);
                    $target = Shipment::create([
                        'order_id'           => $order->id,
                        'shop_id'            => (int) $item->assigned_shop_id,
                        'fulfillment_mode'   => $item->fulfillment_mode ?: 'courier',
                        'pickup_location_id' => $sourceShipment?->pickup_location_id
                            ?? self::pickupLocationIdFor((int) $item->assigned_shop_id),
                        'delivery_mode'      => self::shipmentDeliveryModeFor((int) $item->assigned_shop_id),
                        'status'             => 'pending',
                        'eta_days'           => $item->eta_days,
                        // Deliberately NOT copied from the source: shipping_cost is what the
                        // partner quoted for THAT parcel. This one has not been quoted yet.
                        'expected_delivery_at' => $item->eta_days !== null
                            ? Carbon::now()->addDays((int) $item->eta_days)->toDateString()
                            : null,
                    ]);
                    if (self::supportsShipmentSplitGroup()) {
                        $target->forceFill(['split_group' => $group])->save();
                    }
                }

                $source->update(['quantity' => $available - $move]);
                // The source box just got lighter; its measured weight/dims no longer describe it.
                self::clearStaleParcelMeasurements([(int) $source->shipment_id]);
                ShipmentItem::updateOrCreate(
                    ['shipment_id' => $target->id, 'order_item_id' => $item->id],
                    ['quantity' => $move, 'status' => 'pending'],
                );
                $applied[] = $itemId;
            }

            if ($applied) {
                self::syncItemShipmentColumn($order);
            }
        });

        if ($applied) {
            \Marvel\Database\Models\OrderEvent::record($order->id, 'shipment.split', [
                'mode'        => 'quantity',
                'shipment_id' => $target?->id,
                'lines'       => count($applied),
                'reason'      => $reason,
                'notes'       => $notes,
            ]);
        }

        return [
            'order_id'    => $order->id,
            'applied'     => count($applied),
            'shipment_id' => $target?->id,
            'rejected'    => $rejected,
        ];
    }

    /**
     * Statuses a parcel can still be RE-PLANNED from.
     *
     * Anything further along describes something physical that has already happened —
     * a courier holding the box, a delivery, a bounce — and regrouping it would be a lie
     * about the world. `cancelled` IS re-plannable: the codebase treats a cancelled
     * booking as rebookable, and the parcel's contents were never collected.
     */
    private const REPLANNABLE_STATUSES = ['pending', 'assigned', 'packed', 'cancelled'];

    /**
     * CLUB the given lines into ONE shipment — either a brand-new parcel, or an existing
     * sibling when $targetShipmentId is given (which is the "move items between shipments"
     * case; they are the same operation with a different destination).
     *
     * Items must all come from the same vendor, lane and pickup door: a courier collects
     * from ONE address and drives ONE vehicle, so a parcel spanning two vendors or two
     * yards is a parcel nobody can actually pick up.
     *
     * @return array{order_id:int, applied:int, shipment_id:?int, rejected:array}
     */
    public function clubItems(
        Order $order,
        array $orderItemIds,
        ?int $targetShipmentId = null,
        ?string $reason = null,
        ?string $notes = null
    ): array {
        if (!self::supportsAllocations()) {
            return ['order_id' => $order->id, 'applied' => 0, 'shipment_id' => null, 'rejected' => [
                ['order_item_id' => null, 'reason' => 'clubbing is not available yet on this deployment'],
            ]];
        }

        $applied = [];
        $rejected = [];
        $target = null;
        $movedFrom = [];

        DB::transaction(function () use (
            $order, $orderItemIds, $targetShipmentId, &$applied, &$rejected, &$target, &$movedFrom
        ) {
            Order::whereKey($order->id)->lockForUpdate()->first();
            [$lockedShipmentIds] = $this->lockedFor($order);

            /** @var array<int, OrderItem> $eligible */
            $eligible = [];
            foreach (array_unique(array_map('intval', $orderItemIds)) as $itemId) {
                $item = OrderItem::where('order_id', $order->id)->lockForUpdate()->find($itemId);
                if (!$item) {
                    $rejected[] = ['order_item_id' => $itemId, 'reason' => 'item not found on this order'];
                    continue;
                }
                if (!$item->assigned_shop_id) {
                    $rejected[] = ['order_item_id' => $itemId, 'reason' => 'assign a vendor before grouping this line'];
                    continue;
                }
                $allocs = ShipmentItem::where('order_item_id', $item->id)->lockForUpdate()->get();
                if ($allocs->pluck('shipment_id')->intersect($lockedShipmentIds)->isNotEmpty()) {
                    $rejected[] = ['order_item_id' => $itemId, 'reason' => 'vendor shipment already booked — cancel it first'];
                    continue;
                }
                $eligible[$itemId] = $item;
            }

            if (!$eligible) {
                return;
            }

            // One vendor, one lane, one door — or this is not a parcel.
            $first = reset($eligible);
            $shopId = (int) $first->assigned_shop_id;
            $mode = $first->fulfillment_mode ?: 'courier';
            foreach ($eligible as $itemId => $item) {
                if ((int) $item->assigned_shop_id !== $shopId || ($item->fulfillment_mode ?: 'courier') !== $mode) {
                    $rejected[] = [
                        'order_item_id' => $itemId,
                        'reason'        => 'items from a different vendor or delivery lane cannot travel in one parcel',
                    ];
                    unset($eligible[$itemId]);
                }
            }
            if (!$eligible) {
                return;
            }

            // Resolve the destination parcel.
            if ($targetShipmentId !== null) {
                $target = Shipment::where('order_id', $order->id)->lockForUpdate()->find($targetShipmentId);
                if (!$target) {
                    $rejected[] = ['order_item_id' => null, 'reason' => 'target shipment not found on this order'];
                    $target = null;

                    return;
                }
                if (!$this->isReplannable($target, $lockedShipmentIds)) {
                    $rejected[] = ['order_item_id' => null, 'reason' => $this->replanBlockReason($target)];
                    $target = null;

                    return;
                }
                // Vendor, lane AND door must all match. shop_id alone is not enough: grouping
                // is derived from groupKey(shop, mode, split_group, door), so moving a line
                // into a parcel with a different lane or pickup door sets its split_group to
                // match while leaving the other two terms different — the next regroup()
                // computes a different key and silently undoes the move. A parcel mixing two
                // lanes or two doors is also one nobody can actually collect.
                $targetDoor = $target->pickup_location_id ? (int) $target->pickup_location_id : null;
                $itemsDoor = self::pickupLocationIdFor($shopId);
                if ((int) $target->shop_id !== $shopId
                    || ($target->fulfillment_mode ?: 'courier') !== $mode
                    || $targetDoor !== $itemsDoor) {
                    $rejected[] = [
                        'order_item_id' => null,
                        'reason'        => 'that shipment collects from a different vendor, lane or pickup location',
                    ];
                    $target = null;

                    return;
                }
            } else {
                $source = ShipmentItem::whereIn('order_item_id', array_keys($eligible))->first();
                $target = $this->newParcel(
                    $order,
                    $first,
                    $source ? Shipment::find($source->shipment_id) : null,
                    self::newSplitGroup(),
                );
            }

            $movedFrom = $this->moveAllocations($order, $eligible, $target);
            $applied = array_keys($eligible);
        });

        if ($applied) {
            \Marvel\Database\Models\OrderEvent::record($order->id, 'shipment.items_clubbed', [
                'shipment_id' => $target?->id,
                'lines'       => count($applied),
                'moved_from'  => array_values(array_unique($movedFrom)),
                'reason'      => $reason,
                'notes'       => $notes,
            ]);
        }

        return [
            'order_id'    => $order->id,
            'applied'     => count($applied),
            'shipment_id' => $target?->id,
            'rejected'    => $rejected,
        ];
    }

    /**
     * MERGE several shipments of one vendor door into a single new parcel.
     *
     * A NEW shipment is minted rather than one survivor absorbing the others (a product
     * decision). The sources' booking ATTEMPTS are re-pointed onto it instead of being
     * orphaned, so "this parcel already tried Porter and was refused" survives the merge
     * even though the rows that recorded it are gone.
     *
     * @return array{order_id:int, shipment_id:?int, merged:array<int>, rejected:array}
     */
    public function mergeShipments(Order $order, array $shipmentIds, ?string $reason = null, ?string $notes = null): array
    {
        if (!self::supportsAllocations()) {
            return ['order_id' => $order->id, 'shipment_id' => null, 'merged' => [], 'rejected' => [
                ['shipment_id' => null, 'reason' => 'merging is not available yet on this deployment'],
            ]];
        }

        $ids = array_values(array_unique(array_map('intval', $shipmentIds)));
        if (count($ids) < 2) {
            return ['order_id' => $order->id, 'shipment_id' => null, 'merged' => [], 'rejected' => [
                ['shipment_id' => null, 'reason' => 'select at least two shipments to combine'],
            ]];
        }

        $rejected = [];
        $target = null;
        $merged = [];

        DB::transaction(function () use ($order, $ids, &$rejected, &$target, &$merged) {
            Order::whereKey($order->id)->lockForUpdate()->first();
            [$lockedShipmentIds] = $this->lockedFor($order);

            $sources = Shipment::where('order_id', $order->id)->whereIn('id', $ids)->lockForUpdate()->get();
            if ($sources->count() !== count($ids)) {
                $rejected[] = ['shipment_id' => null, 'reason' => 'one or more shipments are not on this order'];

                return;
            }

            foreach ($sources as $s) {
                if (!$this->isReplannable($s, $lockedShipmentIds)) {
                    $rejected[] = ['shipment_id' => (int) $s->id, 'reason' => $this->replanBlockReason($s)];
                }
            }
            if ($rejected) {
                return;
            }

            // Same vendor, same door, same lane — the three things that make one collection.
            $keys = $sources->map(fn ($s) => implode(':', [
                (int) $s->shop_id,
                $s->fulfillment_mode ?: 'courier',
                (int) ($s->pickup_location_id ?? 0),
            ]))->unique();
            if ($keys->count() > 1) {
                $rejected[] = [
                    'shipment_id' => null,
                    'reason'      => 'shipments must share the same vendor, pickup location and delivery lane to be combined',
                ];

                return;
            }

            $itemIds = ShipmentItem::whereIn('shipment_id', $ids)->pluck('order_item_id')->unique();

            // A line reached through these parcels may ALSO hold units on a parcel the operator
            // did not select — including a live-booked one. moveAllocations() sweeps every
            // allocation of a line onto the target, so absorbing such a line would either undo a
            // deliberate quantity split or EMPTY A SEALED parcel a courier is already carrying,
            // and the units would then ship twice. isReplannable() above only ever saw the
            // sources, so it cannot catch this. clubItems() has the equivalent per-item guard.
            $outside = ShipmentItem::whereIn('order_item_id', $itemIds)
                ->whereNotIn('shipment_id', $ids)->pluck('shipment_id')->unique();
            if ($outside->isNotEmpty()) {
                $other = (int) $outside->first();
                $rejected[] = [
                    'shipment_id' => $other,
                    'reason'      => "a line in this merge also has units on shipment #{$other} — include that shipment, or club the line back together first",
                ];

                return;
            }

            $items = OrderItem::whereIn('id', $itemIds)->get()->keyBy('id')->all();
            if (!$items) {
                $rejected[] = ['shipment_id' => null, 'reason' => 'these shipments carry nothing to combine'];

                return;
            }

            $target = $this->newParcel($order, reset($items), $sources->first(), self::newSplitGroup());

            // Carry the LONGEST promise forward: combining parcels cannot make the customer's
            // delivery date earlier than the slowest thing in the box.
            $eta = $sources->pluck('eta_days')->filter(fn ($d) => $d !== null)->max();
            if ($eta !== null) {
                $target->forceFill([
                    'eta_days'             => (int) $eta,
                    'expected_delivery_at' => Carbon::now()->addDays((int) $eta)->toDateString(),
                ])->save();
            }

            $this->moveAllocations($order, $items, $target);
            $this->repointBookingAttempts($ids, (int) $target->id);

            $merged = $ids;
            // Sources a partner was told about are EMPTIED, not removed: their provider ids and
            // AWB are the audit trail of what was dispatched, and a status callback keyed on a
            // deleted id lands on nothing. They are already excluded from the integrity check's
            // empty-parcel scan because they are terminal.
            $keep = Shipment::whereIn('id', $ids)->get()
                ->filter(fn ($s) => $s->wasEverBooked())->pluck('id')->all();
            Shipment::whereIn('id', array_values(array_diff($ids, $keep)))->delete();
        });

        if ($target && $merged) {
            \Marvel\Database\Models\OrderEvent::record($order->id, 'shipment.merged', [
                'from'   => $merged,
                'to'     => (int) $target->id,
                'reason' => $reason,
                'notes'  => $notes,
            ]);
        }

        return [
            'order_id'    => $order->id,
            'shipment_id' => $target?->id,
            'merged'      => $merged,
            'rejected'    => $rejected,
        ];
    }

    /**
     * Move every allocation of the given items onto $target and align their split_group.
     *
     * The split_group write is load-bearing, not bookkeeping: grouping is DERIVED from
     * groupKey(shop, mode, split_group, door), so an allocation moved without it gets
     * pulled straight back the next time regroup() runs.
     *
     * @param  array<int, OrderItem> $items
     * @return array<int> shipment ids the items came from
     */
    private function moveAllocations(Order $order, array $items, Shipment $target): array
    {
        $movedFrom = [];

        foreach ($items as $item) {
            $allocs = ShipmentItem::where('order_item_id', $item->id)->get();
            $units = 0;
            foreach ($allocs as $alloc) {
                if ((int) $alloc->shipment_id !== (int) $target->id) {
                    $movedFrom[] = (int) $alloc->shipment_id;
                }
                $units += (int) $alloc->quantity;
            }
            // A line already spread over two parcels collapses back into one when it is
            // clubbed — that is what clubbing MEANS, and the units are preserved.
            $units = $units > 0 ? $units : max(1, (int) $item->order_quantity);

            ShipmentItem::where('order_item_id', $item->id)
                ->where('shipment_id', '!=', $target->id)->delete();
            ShipmentItem::updateOrCreate(
                ['shipment_id' => $target->id, 'order_item_id' => $item->id],
                ['quantity' => $units, 'status' => 'pending'],
            );

            $patch = ['shipment_id' => $target->id];
            if (self::supportsSplitGroup()) {
                $patch['split_group'] = self::supportsShipmentSplitGroup() ? $target->split_group : null;
            }
            $item->update($patch);
        }

        // Both ends changed contents, so both readings are now stale.
        self::clearStaleParcelMeasurements(array_merge(array_unique($movedFrom), [(int) $target->id]));

        $this->dropEmptyShipments($order, (int) $target->id);
        self::syncItemShipmentColumn($order);

        return $movedFrom;
    }

    /**
     * Forget an operator's parcel measurements on shipments whose contents just changed.
     *
     * weight_g / length_cm / breadth_cm / height_cm are a human's reading of a SPECIFIC box.
     * Once units leave or join, that reading describes a parcel that no longer exists — and it
     * is what the courier is quoted and billed on (ShippingServiceClient::weightG and
     * packageDims both prefer the override). NULL means "derive from the contents", which is
     * the only honest answer until somebody weighs the new box.
     */
    private static function clearStaleParcelMeasurements(array $shipmentIds): void
    {
        $shipmentIds = array_values(array_filter(array_map('intval', $shipmentIds)));
        if (!$shipmentIds) {
            return;
        }
        try {
            $cols = array_values(array_filter(
                ['weight_g', 'length_cm', 'breadth_cm', 'height_cm'],
                fn ($c) => Schema::hasColumn('shipments', $c),
            ));
            if (!$cols) {
                return;
            }
            Shipment::whereIn('id', $shipmentIds)
                ->update(array_fill_keys($cols, null));

            if (Schema::hasTable('shipment_packages')) {
                // The parcel list is the same reading by another name.
                DB::table('shipment_packages')->whereIn('shipment_id', $shipmentIds)->delete();
            }
        } catch (\Throwable $e) {
            // a stale measurement is bad; failing the move over it is worse
        }
    }

    /** A parcel that has been emptied by a move is not a parcel. Never touches $keepId. */
    private function dropEmptyShipments(Order $order, int $keepId): void
    {
        $empty = Shipment::where('order_id', $order->id)
            ->where('id', '!=', $keepId)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('shipment_items')
                ->whereColumn('shipment_items.shipment_id', 'shipments.id'))
            ->get();

        foreach ($empty as $shipment) {
            // Never bin a parcel a partner knows about — including a CANCELLED one, whose
            // provider ids survive and whose status callbacks still arrive keyed on this id.
            if ($shipment->wasEverBooked()) {
                continue;
            }
            $shipment->delete();
        }
    }

    /** Keep the merged-away parcels' booking history reachable from the parcel that replaced them. */
    private function repointBookingAttempts(array $sourceShipmentIds, int $targetShipmentId): void
    {
        try {
            if (!Schema::hasTable('partner_console_orders')) {
                return;
            }
            DB::table('partner_console_orders')
                ->whereIn('shipment_id', $sourceShipmentIds)
                ->update(['shipment_id' => $targetShipmentId]);

            // RENUMBER. attempt_no is per-shipment (count+1 at write time), so rows arriving
            // from two merged parcels bring their own 1, 1, 2 ... and the target ends up with
            // duplicate and skipped numbers — which is the one thing this column exists to
            // provide. Oldest first, so the sequence still reads as the order things happened.
            $n = 0;
            foreach (
                DB::table('partner_console_orders')->where('shipment_id', $targetShipmentId)
                    ->orderBy('created_at')->orderBy('id')->pluck('id') as $rowId
            ) {
                DB::table('partner_console_orders')->where('id', $rowId)->update(['attempt_no' => ++$n]);
            }
        } catch (\Throwable $e) {
            // An unreachable audit trail must never fail the merge itself.
        }
    }

    /** A fresh parcel for the same vendor, lane and door as the line being grouped. */
    private function newParcel(Order $order, OrderItem $sample, ?Shipment $source, string $group): Shipment
    {
        $shopId = (int) $sample->assigned_shop_id;
        $shipment = Shipment::create([
            'order_id'           => $order->id,
            'shop_id'            => $shopId,
            'fulfillment_mode'   => $sample->fulfillment_mode ?: 'courier',
            'pickup_location_id' => $source?->pickup_location_id ?? self::pickupLocationIdFor($shopId),
            'delivery_mode'      => self::shipmentDeliveryModeFor($shopId),
            'status'             => 'pending',
            'eta_days'           => $sample->eta_days,
            // shipping_cost is deliberately NOT carried: it is what a partner quoted for a
            // DIFFERENT parcel. This one has not been quoted yet.
            'expected_delivery_at' => $sample->eta_days !== null
                ? Carbon::now()->addDays((int) $sample->eta_days)->toDateString()
                : null,
        ]);

        if (self::supportsShipmentSplitGroup()) {
            $shipment->forceFill(['split_group' => $group])->save();
        }

        return $shipment;
    }

    /** varchar(16)-safe marker; the leading digit keeps it distinguishable from the ':L' door term. */
    private static function newSplitGroup(): string
    {
        // 4 digits, not 2: two parcels minted in the same second collided 1-in-90, and a
        // collision makes two distinct groups share one key.
        return substr((string) time(), -6) . random_int(1000, 9999);
    }

    private function isReplannable(Shipment $shipment, array $lockedShipmentIds): bool
    {
        return !in_array((int) $shipment->id, $lockedShipmentIds, true)
            && !self::isLiveBooked($shipment)
            && in_array((string) $shipment->status, self::REPLANNABLE_STATUSES, true);
    }

    private function replanBlockReason(Shipment $shipment): string
    {
        if (self::isLiveBooked($shipment)) {
            return "shipment #{$shipment->id} is already booked — cancel the booking first";
        }

        return "shipment #{$shipment->id} is {$shipment->status} and can no longer be re-planned";
    }

    public function splitShipment(Order $order, array $orderItemIds, bool $separate = true): array
    {
        if (!self::supportsSplitGroup()) {
            return ['order_id' => $order->id, 'applied' => 0, 'rejected' => [
                ['order_item_id' => null, 'reason' => 'split is not available yet on this deployment'],
            ]];
        }
        [, $lockedItemIds] = $this->lockedFor($order);

        // One shared marker so a multi-line split lands in ONE new parcel.
        $group = $separate ? substr((string) time(), -6) . random_int(10, 99) : null;

        $applied = [];
        $rejected = [];
        DB::transaction(function () use ($order, $orderItemIds, $lockedItemIds, $group, &$applied, &$rejected) {
            // Serialise on the order row like every other mutator. A lock only excludes
            // other lock-takers, so one path skipping it lets regroup() rebuild from a
            // snapshot that predates a concurrent split and re-allocate the full
            // order_quantity on top of it.
            Order::whereKey($order->id)->lockForUpdate()->first();
            foreach ($orderItemIds as $rawId) {
                $itemId = (int) $rawId;
                $item = OrderItem::where('order_id', $order->id)->find($itemId);
                if (!$item) {
                    $rejected[] = ['order_item_id' => $itemId, 'reason' => 'item not found on this order'];
                    continue;
                }
                if (in_array($itemId, $lockedItemIds, true)) {
                    $rejected[] = ['order_item_id' => $itemId, 'reason' => 'vendor shipment already booked — cancel it first'];
                    continue;
                }
                if (!$item->assigned_shop_id) {
                    $rejected[] = ['order_item_id' => $itemId, 'reason' => 'assign a vendor before splitting this line'];
                    continue;
                }
                $item->update(['split_group' => $group]);
                $applied[] = $itemId;
            }
            if (count($applied) > 0) {
                $this->regroup($order);
            }
        });

        return ['order_id' => $order->id, 'applied' => count($applied), 'rejected' => $rejected];
    }

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
            // Serialise on the order row like every other mutator — see splitShipment.
            Order::whereKey($order->id)->lockForUpdate()->first();

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
                // Drop the OLD allocations, not just the pointer. Leaving them would give
                // this line rows on two shipments, which regroup() reads as a deliberate
                // quantity split and would then refuse to touch.
                if (self::supportsAllocations()) {
                    ShipmentItem::where('order_item_id', $item->id)->delete();
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
            $this->syncOrderLevelVendor($order);
            \Marvel\Database\Models\OrderEvent::record($order->id, 'items.assigned', [
                'applied'  => count($applied),
                'rejected' => count($rejected),
                'auto'     => false,
            ]);
        }

        return ['order_id' => $order->id, 'applied' => count($applied), 'rejected' => $rejected];
    }

    /**
     * Re-verify, against the vendors' CURRENT inventory, that every line on this shipment
     * can still be fulfilled by its assigned vendor — and repair what can be repaired.
     *
     * Why this exists: the assignment on an order line is a snapshot. A vendor can
     * soft-delete the row, zero the stock, lose approval, or drop the service area between
     * order time and dispatch — and the courier would still be sent to collect from them.
     * VendorInventoryController::deleteInventory diligently repairs the STOREFRONT
     * projection and never touches order_items; the booking funnel is the trust boundary
     * where that asymmetry must be caught, whatever its cause.
     *
     * Disposition per line, in order:
     *  - assigned vendor still in candidatesFor() → VALID, zero writes (the common case).
     *  - stale but LOCKED (any unit on a live-booked sibling parcel) → warn-and-proceed:
     *    the machinery cannot move it, and blocking would dead-end the operator into
     *    cancelling a courier already carrying goods. The failed-pickup path is the backstop.
     *  - stale with a replacement → moved via assignItems() (its transaction, order lock
     *    and fresh re-check close the detection→mutation race; regroup() then puts the
     *    line on the new vendor's parcel, minting one if needed).
     *  - stale with NO replacement → reported, assignment left INTACT. Nulling it would
     *    make regroup() delete the allocation and silently drop the line from every
     *    parcel; a visible stale assignment plus a refused booking is the honest state.
     *
     * Validation quantity is the PIVOT quantity (this parcel's share), never
     * order_quantity — a 3+2 split line must validate each parcel at its own share.
     * Deliberately no vendor_product_price_id fast path: candidatesFor() is the single
     * eligibility authority, and a second predicate here would drift from it.
     *
     * @return array{skipped: ?string, moved: array, stale: array, stale_locked: array, replanned_shipment_ids: array<int>}
     */
    public function revalidateShipmentAssignments(Shipment $shipment): array
    {
        $empty = ['skipped' => null, 'moved' => [], 'stale' => [], 'stale_locked' => [], 'replanned_shipment_ids' => []];

        if (!self::supportsRevalidation()) {
            return ['skipped' => 'schema'] + $empty;
        }
        $order = $shipment->order;
        if (!$order) {
            return ['skipped' => 'no-order'] + $empty;
        }

        [$city, $pincode] = $this->location($order);
        [, $lockedItemIds] = $this->lockedFor($order);

        // Only THIS shipment's lines (a sibling vendor's parcel is not our business here),
        // each at the quantity riding on this parcel.
        if (self::supportsAllocations()) {
            $allocs = ShipmentItem::where('shipment_id', $shipment->id)->get();
            $items = OrderItem::whereIn('id', $allocs->pluck('order_item_id'))->get()->keyBy('id');
            $lines = $allocs->map(fn ($a) => [$items->get($a->order_item_id), (int) $a->quantity])
                ->filter(fn ($pair) => $pair[0] !== null)->values();
        } else {
            $lines = OrderItem::where('shipment_id', $shipment->id)->get()
                ->map(fn ($i) => [$i, (int) $i->order_quantity]);
        }

        $moves = [];
        $stale = [];
        $staleLocked = [];

        foreach ($lines as [$item, $qty]) {
            if (!$item->assigned_shop_id || !$item->product_id) {
                continue; // legacy / unassigned row — nothing to validate
            }
            $candidates = $this->engine->candidatesFor(
                (int) $item->product_id,
                $item->variation_option_id ? (int) $item->variation_option_id : null,
                max(1, $qty),
                $city,
                $pincode
            );
            if (collect($candidates)->firstWhere('shop_id', (int) $item->assigned_shop_id)) {
                continue; // still eligible — the fast path, zero writes
            }
            if (in_array((int) $item->id, $lockedItemIds, true)) {
                $staleLocked[] = [
                    'order_item_id' => (int) $item->id,
                    'product_id'    => (int) $item->product_id,
                    'shop_id'       => (int) $item->assigned_shop_id,
                    'reason'        => 'vendor no longer has this product, but the line is sealed under a live booking',
                ];
                continue;
            }
            $best = $candidates[0] ?? null;
            if ($best) {
                $moves[] = [
                    'order_item_id' => (int) $item->id,
                    'shop_id'       => (int) $best['shop_id'],
                    'from_shop_id'  => (int) $item->assigned_shop_id,
                ];
            } else {
                $stale[] = [
                    'order_item_id'    => (int) $item->id,
                    'product_id'       => (int) $item->product_id,
                    'assigned_shop_id' => (int) $item->assigned_shop_id,
                    'reason'           => 'no eligible vendor currently has this product',
                ];
            }
        }

        if (!$moves && !$stale && !$staleLocked) {
            return $empty;
        }

        $replanned = [];
        $movedApplied = [];
        if ($moves) {
            $before = Shipment::where('order_id', $order->id)->pluck('id')->map(fn ($i) => (int) $i)->all();
            try {
                $res = $this->assignItems($order, $moves);
                $rejectedIds = collect($res['rejected'] ?? [])->pluck('order_item_id')->all();
                foreach ($moves as $move) {
                    if (in_array($move['order_item_id'], $rejectedIds, true)) {
                        // The replacement vanished between detection and the locked re-check —
                        // the brief's "failed reassignment". Known-bad assignment: fail closed.
                        $stale[] = [
                            'order_item_id'    => $move['order_item_id'],
                            'assigned_shop_id' => $move['from_shop_id'],
                            'reason'           => 'reassignment failed — vendor became ineligible during re-check',
                        ];
                    } else {
                        $movedApplied[] = $move;
                    }
                }
            } catch (\Throwable $e) {
                // Detection already proved these assignments are bad; an infra failure of
                // the FIX does not make dispatching against the stale vendor safe.
                foreach ($moves as $move) {
                    $stale[] = [
                        'order_item_id'    => $move['order_item_id'],
                        'assigned_shop_id' => $move['from_shop_id'],
                        'reason'           => 'reassignment failed: ' . $e->getMessage(),
                    ];
                }
            }
            $after = Shipment::where('order_id', $order->id)->pluck('id')->map(fn ($i) => (int) $i)->all();
            $replanned = array_values(array_diff($after, $before));

            if ($movedApplied) {
                \Marvel\Database\Models\OrderEvent::record($order->id, 'assignment.revalidated', [
                    'shipment_id'            => (int) $shipment->id,
                    'moved'                  => $movedApplied,
                    'replanned_shipment_ids' => $replanned,
                    'auto'                   => true,
                ]);
            }
        }

        if ($stale) {
            \Marvel\Database\Models\OrderEvent::record($order->id, 'assignment.stale', [
                'shipment_id' => (int) $shipment->id,
                'items'       => $stale,
                'blocked'     => true,
            ]);
        }
        if ($staleLocked) {
            \Marvel\Database\Models\OrderEvent::record($order->id, 'assignment.stale', [
                'shipment_id' => (int) $shipment->id,
                'items'       => $staleLocked,
                'blocked'     => false,
                'locked'      => true,
            ]);
            \Illuminate\Support\Facades\Log::warning('courier.revalidation.stale_locked', [
                'order_id'    => $order->id,
                'shipment_id' => $shipment->id,
                'items'       => $staleLocked,
            ]);
        }

        return [
            'skipped'                => null,
            'moved'                  => $movedApplied,
            'stale'                  => $stale,
            'stale_locked'           => $staleLocked,
            'replanned_shipment_ids' => $replanned,
        ];
    }

    /**
     * Deploy-lag guard for revalidation, same non-memoized shape as supportsAllocations():
     * a box (or a test schema) whose order_items predate per-line assignment has nothing
     * to validate, and must book exactly as before rather than fatal on a missing column.
     */
    private static function supportsRevalidation(): bool
    {
        try {
            return Schema::hasColumn('order_items', 'assigned_shop_id')
                && Schema::hasTable('vendor_product_prices');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * A live-booked shipment (partner order / AWB exists and it isn't cancelled) is SEALED:
     * its rows and item links must never be deleted, rebuilt or reassigned — doing so orphans
     * the real partner job, loses the AWB, and deafens the status webhook (which resolves by
     * shipment id). Cancelling the booking is the only way to unlock it.
     */
    private static function isLiveBooked(Shipment $s): bool
    {
        // Delegates: the model owns the definition of "sealed". This used to be a second
        // copy of the predicate, which would now silently disagree with the model about a
        // booking in flight.
        return $s->isLiveBooked();
    }

    /** @return array{0: array<int>, 1: array<int>} [locked shipment ids, item ids on them] */
    private function lockedFor(Order $order): array
    {
        $lockedShipmentIds = Shipment::where('order_id', $order->id)->get()
            ->filter(fn ($s) => self::isLiveBooked($s))
            ->pluck('id')->all();

        if (!$lockedShipmentIds) {
            return [[], []];
        }

        // Via allocations, not order_items.shipment_id: that column is NULL for a line
        // split across parcels, so a scalar lookup would report a booked line as free
        // and let a re-plan move goods out from under a courier already carrying them.
        $lockedItemIds = self::supportsAllocations()
            ? ShipmentItem::whereIn('shipment_id', $lockedShipmentIds)
                ->pluck('order_item_id')->unique()->values()->all()
            : OrderItem::where('order_id', $order->id)
                ->whereIn('shipment_id', $lockedShipmentIds)->pluck('id')->all();

        return [$lockedShipmentIds, array_map('intval', $lockedItemIds)];
    }

    /**
     * Railway/EC2 migrate in the background after deploy — same guard as
     * supportsSplitGroup/supportsMarginSnapshot, so assignment keeps working on a box
     * whose schema has not caught up yet.
     */
    private static function supportsAllocations(): bool
    {
        // Deliberately NOT memoized in a static: the sibling guards are, and that is only
        // safe because every schema they meet happens to agree. A static answer is wrong
        // the moment two schemas exist in one process, which is exactly what the test
        // suite does (each case builds its own in-memory database).
        //
        // ponytail: one lightweight lookup per call, on an admin action; memoize per
        // connection if it ever shows up in a profile.
        try {
            return Schema::hasTable('shipment_items');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The group identity of an EXISTING shipment — the match key for reconcile-in-place. */
    private static function groupKeyOfShipment(Shipment $s): string
    {
        return self::groupKey(
            (int) $s->shop_id,
            $s->fulfillment_mode ?: 'courier',
            self::supportsShipmentSplitGroup() ? $s->split_group : null,
            $s->pickup_location_id ? (int) $s->pickup_location_id : null,
        );
    }

    /**
     * Make this shipment's allocations exactly $allocs (a list of
     * ['order_item_id' => int, 'quantity' => int]). Updates in place so allocation ids —
     * and therefore any per-parcel status already stamped on them — survive a re-plan.
     */
    private static function syncAllocations(Shipment $shipment, array $allocs): void
    {
        if (!self::supportsAllocations()) {
            return;
        }

        // AGGREGATE first: one order_item can legitimately arrive TWICE under one key. Two
        // sibling parcels minted in the same second share a split_group marker (see
        // newSplitGroup), so both allocations of a split line resolve to the same groupKey.
        // Writing them in sequence made the second silently overwrite the first — units lost —
        // or collide with shipment_items_ship_item_unique and 500.
        $wanted = [];
        foreach ($allocs as $alloc) {
            $id = (int) $alloc['order_item_id'];
            $wanted[$id] = ($wanted[$id] ?? 0) + max(1, (int) $alloc['quantity']);
        }

        $existing = ShipmentItem::where('shipment_id', $shipment->id)->get()->keyBy('order_item_id');
        $keep = [];

        foreach ($wanted as $itemId => $qty) {
            $keep[] = $itemId;

            $row = $existing->get($itemId);
            if ($row) {
                if ((int) $row->quantity !== $qty) {
                    $row->update(['quantity' => $qty]);
                }
                continue;
            }
            ShipmentItem::create([
                'shipment_id'   => $shipment->id,
                'order_item_id' => $itemId,
                'quantity'      => $qty,
                'status'        => 'pending',
            ]);
        }

        ShipmentItem::where('shipment_id', $shipment->id)
            ->whereNotIn('order_item_id', $keep ?: [0])
            ->delete();
    }

    /**
     * Refresh the DERIVED order_items.shipment_id pointer for a whole order.
     *
     * Exactly one allocation -> that shipment. Zero or several -> NULL. NULL for a split
     * line is deliberate: an unmigrated reader then omits the line rather than showing
     * every unit on every parcel, and undercounting is the safe direction for anything
     * that reaches a courier or a card.
     */
    private static function syncItemShipmentColumn(Order $order): void
    {
        if (!self::supportsAllocations()) {
            return;
        }

        $byItem = ShipmentItem::whereIn(
            'order_item_id',
            OrderItem::where('order_id', $order->id)->pluck('id')
        )->get()->groupBy('order_item_id');

        foreach (OrderItem::where('order_id', $order->id)->get() as $item) {
            $shipmentIds = ($byItem->get($item->id) ?? collect())
                ->pluck('shipment_id')->unique()->values();
            $target = $shipmentIds->count() === 1 ? (int) $shipmentIds->first() : null;
            if ((int) $item->shipment_id !== (int) $target) {
                $item->update(['shipment_id' => $target]);
            }
        }
    }

    /** Set an item's assignment + attach it to the matching shipment (create if needed). */
    private function applyAssignment(Order $order, OrderItem $item, array $pick, array &$shipments, string $status): void
    {
        $shopId = (int) $pick['shop_id'];
        $mode = $pick['fulfillment_mode'] ?? 'courier';
        $split = self::supportsSplitGroup() ? $item->split_group : null;
        $pickupLocationId = self::pickupLocationIdFor($shopId);
        $key = self::groupKey($shopId, $mode, $split, $pickupLocationId);

        if (!isset($shipments[$key])) {
            $eta = $pick['eta_days'] ?? null;
            $shipments[$key] = Shipment::create([
                'order_id'             => $order->id,
                'shop_id'              => $shopId,
                // The door this leg leaves from, recorded when the group is formed rather than
                // resolved again at booking — see pickupLocationIdFor.
                'pickup_location_id'   => $pickupLocationId,
                'fulfillment_mode'     => $mode,
                'delivery_mode'        => self::shipmentDeliveryModeFor($shopId),
                'status'               => 'pending',
                'eta_days'             => $eta,
                'expected_delivery_at' => $eta !== null ? Carbon::now()->addDays((int) $eta)->toDateString() : null,
                'shipping_cost'        => $pick['shipping_cost'] ?? null,
            ]);
            // The marker is part of the group identity ($key above already includes it), so a
            // parcel created without it does not match its OWN key on the next pass — regroup
            // then treats it as a stranger and rebuilds it. regroup()'s create path stamps it;
            // this one silently did not.
            if ($split !== null && self::supportsShipmentSplitGroup()) {
                $shipments[$key]->forceFill(['split_group' => $split])->save();
            }
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
                $this->queueProjectionRefresh([$item]);
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

        // The allocation is the real link; shipment_id above is its derived shadow. An
        // auto-assigned line always travels whole — a quantity split is an explicit
        // operator action (splitByQuantity), never something the scorer produces.
        if (self::supportsAllocations()) {
            ShipmentItem::updateOrCreate(
                ['shipment_id' => $shipment->id, 'order_item_id' => $item->id],
                ['quantity' => max(1, (int) $item->order_quantity), 'status' => 'pending'],
            );
        }
    }

    /**
     * Reconcile the order's shipments against the items' current assignment, IN PLACE.
     *
     * This used to delete every unbooked shipment and build fresh rows, carrying
     * shipping_cost / mode / expected_delivery_at across in a keyed map. Two things were
     * wrong with that. The map keyed on $s->split_group, which nothing ever wrote, so the
     * carry-over silently missed on every split order and those three fields were lost.
     * And a recreated row is a new id: anything holding a shipment id — an operator's
     * open tab, a package row, an event — was pointing at a tombstone.
     *
     * Matching existing rows by their group key instead keeps the id, and with it every
     * field on the row, for free. There is nothing left to carry.
     *
     * Live-booked (sealed) shipments and their items are never touched: deleting one
     * orphans the real partner job and deafens the status webhook.
     */
    private function regroup(Order $order): void
    {
        $order->load('items');
        [$lockedShipmentIds, $lockedItemIds] = $this->lockedFor($order);

        $allocationsByItem = self::supportsAllocations()
            ? ShipmentItem::whereIn('order_item_id', $order->items->pluck('id'))->get()->groupBy('order_item_id')
            : collect();

        // ---- 1. What SHOULD exist: groupKey => [ ['order_item_id'=>, 'quantity'=>], ... ]
        $desired = [];
        $etaByKey = [];
        foreach ($order->items as $item) {
            $allocs = $allocationsByItem->get($item->id) ?? collect();

            if (in_array((int) $item->id, $lockedItemIds, true)) {
                // Sealed under a live booking — but lockedFor() locks the WHOLE line as soon
                // as ANY of its units sit on a booked parcel, while the rest of the line may
                // sit on unbooked ones. A bare `continue` left those out of $desired, so they
                // matched no key and were destroyed: swept by syncAllocations' prune, or
                // dropped with the parcel as a step-3 leftover. Paid-for units simply vanished,
                // with no cancellation and no event.
                //
                // Re-declare them against the parcel they ALREADY sit on: nothing moves,
                // nothing is destroyed, and the sealed parcel is never touched because it is
                // not in $pool.
                foreach ($allocs as $alloc) {
                    if (in_array((int) $alloc->shipment_id, $lockedShipmentIds, true)) {
                        continue;
                    }
                    $shipment = Shipment::find($alloc->shipment_id);
                    if (!$shipment) {
                        continue;
                    }
                    $key = self::groupKeyOfShipment($shipment);
                    $desired[$key][] = ['order_item_id' => (int) $item->id, 'quantity' => (int) $alloc->quantity];
                    $etaByKey[$key] = $etaByKey[$key] ?? $item->eta_days;
                }
                continue;
            }

            // An operator deliberately spread this line over more than one parcel.
            // regroup() is the AUTOMATIC grouper and must never silently undo that —
            // collapsing it is what "merge back" is for.
            if ($allocs->pluck('shipment_id')->unique()->count() > 1) {
                foreach ($allocs as $alloc) {
                    $shipment = Shipment::find($alloc->shipment_id);
                    if (!$shipment) {
                        continue;
                    }
                    $key = self::groupKeyOfShipment($shipment);
                    $desired[$key][] = ['order_item_id' => (int) $item->id, 'quantity' => (int) $alloc->quantity];
                    $etaByKey[$key] = $etaByKey[$key] ?? $item->eta_days;
                }
                continue;
            }

            if (!$item->assigned_shop_id) {
                if (self::supportsAllocations()) {
                    ShipmentItem::where('order_item_id', $item->id)->delete();
                }
                $item->update(['shipment_id' => null]);
                continue;
            }

            $mode = $item->fulfillment_mode ?: 'courier';
            $split = self::supportsSplitGroup() ? $item->split_group : null;
            // The door is part of the key: a courier collects from an ADDRESS, so a vendor
            // loading from two yards is two collections. applyAssignment() always passed
            // it; this call did not, which merged a two-door vendor into one impossible
            // pickup on every regroup.
            // The door a parcel ALREADY leaves from wins over a freshly-resolved one.
            //
            // pickupLocationIdFor() answers "the vendor's default door TODAY". Resolving it here
            // and matching that against each shipment's STORED door meant that the moment a
            // vendor added or re-defaulted a door, every existing parcel's key stopped matching
            // — so every shipment on every open order was destroyed and recreated, losing its
            // id, its quoted cost and its lane. That is precisely what reconcile-in-place exists
            // to prevent, and re-resolving here quietly reintroduced it.
            $existingDoor = null;
            foreach ($allocs as $alloc) {
                $current = Shipment::find($alloc->shipment_id);
                if ($current && !in_array((int) $current->id, $lockedShipmentIds, true)) {
                    $existingDoor = $current->pickup_location_id ? (int) $current->pickup_location_id : null;
                    break;
                }
            }
            $door = $allocs->isNotEmpty()
                ? $existingDoor
                : self::pickupLocationIdFor((int) $item->assigned_shop_id);
            $key = self::groupKey((int) $item->assigned_shop_id, $mode, $split, $door);

            $desired[$key][] = ['order_item_id' => (int) $item->id, 'quantity' => max(1, (int) $item->order_quantity)];
            $etaByKey[$key] = $etaByKey[$key] ?? $item->eta_days;
        }

        // ---- 2. Match onto existing unbooked rows. Ids survive.
        // A LIST, not keyBy(groupKey): two shipments can legitimately share a key (a
        // vendor split whose marker column the schema does not carry yet), and keyBy
        // keeps only the last — the other would vanish from the pool and so never be
        // recognised as an emptied parcel in step 3.
        $pool = Shipment::where('order_id', $order->id)
            ->whereNotIn('id', $lockedShipmentIds ?: [0])
            ->get();

        foreach ($desired as $key => $allocs) {
            [$shopId, $mode, $split, $door] = self::parseGroupKey($key);
            $eta = $etaByKey[$key] ?? null;

            // Consume the first pool row matching this key; whatever is left over at the
            // end held nothing and gets dropped.
            $slot = $pool->search(fn ($s) => self::groupKeyOfShipment($s) === $key);
            $shipment = $slot === false ? null : $pool->pull($slot);

            if ($shipment) {
                // Restamped (not carried) on purpose: reassignment to a platform vendor
                // must clear a prior 'self' and vice versa. Everything else on the row
                // stays because the ROW stays.
                $patch = ['delivery_mode' => self::shipmentDeliveryModeFor($shopId)];
                if ($shipment->eta_days === null && $eta !== null) {
                    $patch['eta_days'] = $eta;
                }
                $shipment->forceFill($patch)->save();
            } else {
                $shipment = Shipment::create([
                    'order_id'             => $order->id,
                    'shop_id'              => $shopId,
                    'fulfillment_mode'     => $mode,
                    // Dropped by the old create path, which is why a split or multi-door
                    // order never matched its own group key on the next pass.
                    'pickup_location_id'   => $door,
                    'delivery_mode'        => self::shipmentDeliveryModeFor($shopId),
                    'status'               => 'pending',
                    'eta_days'             => $eta,
                    'expected_delivery_at' => $eta !== null
                        ? Carbon::now()->addDays((int) $eta)->toDateString()
                        : null,
                ]);
                if (self::supportsShipmentSplitGroup() && $split !== null) {
                    $shipment->forceFill(['split_group' => $split])->save();
                }
            }

            self::syncAllocations($shipment, $allocs);
            OrderItem::whereIn('id', array_column($allocs, 'order_item_id'))
                ->whereNull('shipment_id')
                ->update(['shipment_id' => $shipment->id]);
        }

        // ---- 3. Anything still in the pool now carries nothing. An empty parcel is not
        //         a parcel — drop it and its allocations.
        foreach ($pool as $leftover) {
            if (self::supportsAllocations()) {
                ShipmentItem::where('shipment_id', $leftover->id)->delete();
            }
            $leftover->delete();
        }

        self::syncItemShipmentColumn($order);
    }

    /**
     * Inverse of groupKey(): "shopId:mode[:split][:Ldoor]" => [shopId, mode, split, door].
     * The split marker never starts with 'L' (it is digits — see splitShipment), so the
     * door term is unambiguous.
     *
     * @return array{0:int,1:string,2:?string,3:?int}
     */
    private static function parseGroupKey(string $key): array
    {
        $parts = explode(':', $key);
        $shopId = (int) array_shift($parts);
        $mode = (string) array_shift($parts);
        $split = null;
        $door = null;
        foreach ($parts as $part) {
            if ($part !== '' && $part[0] === 'L') {
                $door = (int) substr($part, 1);
                continue;
            }
            $split = $part;
        }
        return [$shopId, $mode, $split, $door];
    }

    /**
     * Whether SHIPMENTS carries split_group yet — a different column from the order_items
     * one supportsSplitGroup() checks, added in a later migration. Writing the key on a
     * box (or a test schema) that predates it is a hard SQL error, not a silent null.
     */
    private static function supportsShipmentSplitGroup(): bool
    {
        try {
            return Schema::hasColumn('shipments', 'split_group');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Whether order_items carries the split-group column yet (deploy-lag guard). */
    private static function supportsSplitGroup(): bool
    {
        static $supports = null;
        if ($supports === null) {
            try {
                $supports = \Illuminate\Support\Facades\Schema::hasColumn('order_items', 'split_group');
            } catch (\Throwable $e) {
                $supports = false;
            }
        }
        return $supports;
    }

    /**
     * Grouping key for a shipment row. A vendor's lines share ONE shipment by
     * default; `split_group` is the operator's explicit "send this part
     * separately" marker (large orders that need two vehicles), so it has to
     * survive regroup() — otherwise the rows merge straight back together.
     */
    private static function groupKey(int $shopId, string $mode, $splitGroup = null, $pickupLocationId = null): string
    {
        $split = $splitGroup === null || $splitGroup === '' ? '' : ':' . $splitGroup;
        // The physical ORIGIN, not just the vendor. A courier collects from an address, so two
        // doors is two collections however you label them — one shipment naming both is a
        // shipment nobody can actually pick up.
        //
        // Inert today, deliberately: nothing yet says which of a vendor's doors holds which
        // stock, so every line resolves to the same default door and the grouping is unchanged.
        // The term is here so the day a location-aware inventory signal exists, splitting is a
        // data change rather than a rewrite of the grouping rule.
        $door = $pickupLocationId ? ':L' . $pickupLocationId : '';
        return $shopId . ':' . $mode . $split . $door;
    }

    /**
     * Which of the vendor's doors this line ships from.
     *
     * The vendor's default today — candidatesFor selects a SHOP, and neither it nor order_items
     * carries a location, so there is no per-line signal to be more specific with. Resolving it
     * here anyway is what puts the answer on the shipment: shipments.pickup_location_id existed
     * with no production writer at all, so every leg silently booked whatever the default was at
     * BOOKING time rather than what was chosen at assignment.
     *
     * Null on any failure — the pickup table is deploy-lag guarded elsewhere for the same reason,
     * and a missing door must never stop an order being grouped.
     */
    private static function pickupLocationIdFor(int $shopId): ?int
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('vendor_pickup_locations')) {
                return null;
            }
            $id = \Marvel\Database\Models\VendorPickupLocation::where('shop_id', $shopId)
                ->orderByDesc('is_default')->orderBy('id')->value('id');
            return $id ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * shipments.delivery_mode routing key: 'self' when the vendor fulfils its
     * own deliveries (shops.delivery_mode = 'self'), else NULL (courier stack).
     * Deploy-lag safe: a missing column just means platform routing.
     */
    private static function shipmentDeliveryModeFor(int $shopId): ?string
    {
        try {
            $mode = \Marvel\Database\Models\Shop::whereKey($shopId)->value('delivery_mode');
            return $mode === 'self' ? 'self' : null;
        } catch (\Throwable $e) {
            return null;
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
