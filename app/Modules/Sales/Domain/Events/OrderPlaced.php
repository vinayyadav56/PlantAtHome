<?php

namespace App\Modules\Sales\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * A customer order was placed and split into per-vendor sub-orders. Notifications
 * alert each nursery + the customer; Analytics counts; Search may adjust demand
 * signals. The customer sees one order; each nursery only its own sub-order.
 */
final class OrderPlaced extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $orderUuid,
        private readonly ?string $customerUuid,
        private readonly array $subOrders,   // [{uuid, nursery_id}]
        private readonly array $totals,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'sales.order_placed';
    }

    public function payload(): array
    {
        return [
            'order_uuid'    => $this->orderUuid,
            'customer_uuid' => $this->customerUuid,
            'sub_orders'    => $this->subOrders,
            'totals'        => $this->totals,
        ];
    }
}
