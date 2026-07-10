<?php

namespace App\Modules\Sales\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * A sub-order was refunded (its stock has been restocked). Payments issues the
 * refund downstream; Analytics adjusts; the customer is notified.
 */
final class OrderRefunded extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $subOrderUuid,
        private readonly string $nurseryId,
        private readonly array $totals,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'sales.order_refunded';
    }

    public function payload(): array
    {
        return [
            'sub_order_uuid' => $this->subOrderUuid,
            'nursery_id'     => $this->nurseryId,
            'totals'         => $this->totals,
        ];
    }
}
