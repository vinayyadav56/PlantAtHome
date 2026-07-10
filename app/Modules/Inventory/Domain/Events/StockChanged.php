<?php

namespace App\Modules\Inventory\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * On-hand stock for a SKU/vendor changed (adjustment, sale, restock). Search
 * (Phase 9) reindexes availability; Configuration may invalidate resolved
 * configs that annotate stock. Carries the SKU identity + new levels.
 */
final class StockChanged extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $sellableType,
        private readonly string $sellableUuid,
        private readonly string $nurseryId,
        private readonly int $qtyOnHand,
        private readonly int $qtyReserved,
        private readonly string $reason,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'inventory.stock_changed';
    }

    public function payload(): array
    {
        return [
            'sellable_type' => $this->sellableType,
            'sellable_uuid' => $this->sellableUuid,
            'nursery_id'    => $this->nurseryId,
            'qty_on_hand'   => $this->qtyOnHand,
            'qty_reserved'  => $this->qtyReserved,
            'available'     => max(0, $this->qtyOnHand - $this->qtyReserved),
            'reason'        => $this->reason,
        ];
    }
}
