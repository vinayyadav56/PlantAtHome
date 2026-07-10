<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\Events\StockChanged;
use App\Modules\Inventory\Domain\ReservationStatus;
use App\Modules\Inventory\Domain\SellableType;
use App\Modules\Inventory\Infrastructure\Models\InventoryItem;
use App\Modules\Inventory\Infrastructure\Models\Movement;
use App\Modules\Inventory\Infrastructure\Models\Reservation;
use App\Shared\Application\DomainActionException;
use App\Shared\Events\EventPublisher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Inventory use-cases (Section 4.5 & 8). All stock mutations run inside a
 * transaction and take a row lock (FOR UPDATE) on the inventory item, so
 * concurrent reservations against the same SKU are serialized — exactly one
 * caller can consume the last unit, guaranteeing no oversell. On-hand is
 * append-only-logged to the movement ledger and stays reconcilable.
 */
class InventoryService
{
    public const DEFAULT_TTL = 900; // 15 minutes

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly EventPublisher $events,
    ) {
    }

    /** Set absolute on-hand for a SKU/vendor, logging the delta. */
    public function upsertStock(string $type, string $sellableUuid, string $nurseryId, int $qtyOnHand, bool $track = true): InventoryItem
    {
        $this->assertType($type);

        return $this->db->transaction(function () use ($type, $sellableUuid, $nurseryId, $qtyOnHand, $track) {
            $item = InventoryItem::where('sellable_type', $type)
                ->where('sellable_uuid', $sellableUuid)
                ->where('nursery_id', $nurseryId)
                ->lockForUpdate()
                ->first();

            if (! $item) {
                $item = new InventoryItem([
                    'sellable_type' => $type, 'sellable_uuid' => $sellableUuid,
                    'nursery_id' => $nurseryId, 'qty_on_hand' => 0, 'qty_reserved' => 0,
                ]);
            }

            $delta = $qtyOnHand - (int) $item->qty_on_hand;
            $item->qty_on_hand = $qtyOnHand;
            $item->track = $track;
            $item->save();

            if ($delta !== 0) {
                $this->logMovement($item, $delta, 'adjustment');
            }
            $this->emitStockChanged($item, 'adjustment');

            return $item;
        });
    }

    /** Current available (on-hand − reserved). A non-tracked SKU is unlimited. */
    public function available(string $type, string $sellableUuid, string $nurseryId): int
    {
        $item = InventoryItem::where('sellable_type', $type)
            ->where('sellable_uuid', $sellableUuid)
            ->where('nursery_id', $nurseryId)
            ->first();

        if (! $item) {
            return 0;
        }

        return $item->track ? $item->available() : PHP_INT_MAX;
    }

    /**
     * Atomically reserve stock for every line (all-or-nothing). Items are locked
     * in a stable id order to avoid deadlocks between concurrent checkouts.
     *
     * @param  array<int,array{sellable_type:string,sellable_uuid:string,nursery_id:string,qty:int}>  $lines
     * @return array<int,Reservation>
     *
     * @throws DomainActionException INSUFFICIENT_STOCK (409) if any line can't be met.
     */
    public function reserve(array $lines, string $checkoutSessionId, int $ttlSeconds = self::DEFAULT_TTL): array
    {
        return $this->db->transaction(function () use ($lines, $checkoutSessionId, $ttlSeconds) {
            // Resolve item ids first, then lock in ascending id order.
            $resolved = [];
            foreach ($lines as $line) {
                $this->assertType($line['sellable_type']);
                $item = InventoryItem::where('sellable_type', $line['sellable_type'])
                    ->where('sellable_uuid', $line['sellable_uuid'])
                    ->where('nursery_id', $line['nursery_id'])
                    ->first();
                if (! $item) {
                    throw DomainActionException::conflict('This item is not stocked for the selected vendor.', 'INSUFFICIENT_STOCK');
                }
                $resolved[] = ['id' => $item->id, 'qty' => max(1, (int) $line['qty'])];
            }
            usort($resolved, fn ($a, $b) => $a['id'] <=> $b['id']);

            $reservations = [];
            foreach ($resolved as $r) {
                $item = InventoryItem::whereKey($r['id'])->lockForUpdate()->first();

                if ($item->track && $item->available() < $r['qty']) {
                    // Rolls back every reservation made in this transaction.
                    throw DomainActionException::conflict(
                        "Insufficient stock: {$item->available()} available, {$r['qty']} requested.",
                        'INSUFFICIENT_STOCK',
                    );
                }

                if ($item->track) {
                    $item->qty_reserved += $r['qty'];
                    $item->save();
                }

                $reservations[] = Reservation::create([
                    'inventory_item_id'   => $item->id,
                    'checkout_session_id' => $checkoutSessionId,
                    'qty'                 => $r['qty'],
                    'expires_at'          => Carbon::now()->addSeconds($ttlSeconds),
                    'status'              => ReservationStatus::ACTIVE,
                ]);
            }

            return $reservations;
        });
    }

    /** Commit a checkout's reservations: deduct on-hand, log sales. */
    public function commit(string $checkoutSessionId): int
    {
        return $this->db->transaction(function () use ($checkoutSessionId) {
            $reservations = Reservation::where('checkout_session_id', $checkoutSessionId)
                ->where('status', ReservationStatus::ACTIVE)
                ->orderBy('inventory_item_id')
                ->get();

            $committed = 0;
            foreach ($reservations as $reservation) {
                $item = InventoryItem::whereKey($reservation->inventory_item_id)->lockForUpdate()->first();
                if ($item->track) {
                    $item->qty_on_hand = max(0, $item->qty_on_hand - $reservation->qty);
                    $item->qty_reserved = max(0, $item->qty_reserved - $reservation->qty);
                    $item->save();
                    $this->logMovement($item, -$reservation->qty, 'sale', 'reservation', $reservation->uuid);
                    $this->emitStockChanged($item, 'sale');
                }
                $reservation->update(['status' => ReservationStatus::COMMITTED]);
                $committed++;
            }

            return $committed;
        });
    }

    /** Return sold stock to on-hand (e.g. a refund/return), logging the movement. */
    public function restock(string $type, string $sellableUuid, string $nurseryId, int $qty, ?string $refType = null, ?string $refId = null): void
    {
        if ($qty <= 0) {
            return;
        }

        $this->db->transaction(function () use ($type, $sellableUuid, $nurseryId, $qty, $refType, $refId) {
            $item = InventoryItem::where('sellable_type', $type)
                ->where('sellable_uuid', $sellableUuid)
                ->where('nursery_id', $nurseryId)
                ->lockForUpdate()
                ->first();
            if (! $item) {
                return; // not tracked — nothing to restock
            }
            $item->qty_on_hand += $qty;
            $item->save();
            $this->logMovement($item, $qty, 'restock', $refType, $refId);
            $this->emitStockChanged($item, 'restock');
        });
    }

    /** Release a checkout's active reservations back to available. */
    public function release(string $checkoutSessionId): int
    {
        return $this->db->transaction(function () use ($checkoutSessionId) {
            $reservations = Reservation::where('checkout_session_id', $checkoutSessionId)
                ->where('status', ReservationStatus::ACTIVE)
                ->orderBy('inventory_item_id')
                ->get();

            return $this->releaseReservations($reservations, ReservationStatus::RELEASED);
        });
    }

    /** Sweep expired reservations, returning their held stock. Returns count released. */
    public function releaseExpired(int $limit = 500): int
    {
        return $this->db->transaction(function () use ($limit) {
            $reservations = Reservation::where('status', ReservationStatus::ACTIVE)
                ->where('expires_at', '<', Carbon::now())
                ->orderBy('inventory_item_id')
                ->limit($limit)
                ->get();

            return $this->releaseReservations($reservations, ReservationStatus::EXPIRED);
        });
    }

    /** On-hand reconciled from the ledger (sum of deltas) — for integrity checks. */
    public function ledgerBalance(InventoryItem $item): int
    {
        return (int) $item->movements()->sum('delta');
    }

    /* ── internals ─────────────────────────────────────────────────────────── */

    /** @param \Illuminate\Support\Collection<int,Reservation> $reservations */
    private function releaseReservations($reservations, string $status): int
    {
        $count = 0;
        foreach ($reservations as $reservation) {
            $item = InventoryItem::whereKey($reservation->inventory_item_id)->lockForUpdate()->first();
            if ($item && $item->track) {
                $item->qty_reserved = max(0, $item->qty_reserved - $reservation->qty);
                $item->save();
            }
            $reservation->update(['status' => $status]);
            $count++;
        }

        return $count;
    }

    private function logMovement(InventoryItem $item, int $delta, string $reason, ?string $refType = null, ?string $refId = null): void
    {
        Movement::create([
            'inventory_item_id' => $item->id,
            'delta'             => $delta,
            'reason'            => $reason,
            'ref_type'          => $refType,
            'ref_id'            => $refId,
        ]);
    }

    private function emitStockChanged(InventoryItem $item, string $reason): void
    {
        $this->events->publish(new StockChanged(
            $item->sellable_type, $item->sellable_uuid, $item->nursery_id,
            (int) $item->qty_on_hand, (int) $item->qty_reserved, $reason,
        ));
    }

    private function assertType(string $type): void
    {
        if (! SellableType::isValid($type)) {
            throw DomainActionException::unprocessable("Invalid sellable_type: {$type}", 'INVALID_SELLABLE_TYPE', 'sellable_type');
        }
    }
}
