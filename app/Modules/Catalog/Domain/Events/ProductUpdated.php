<?php

namespace App\Modules\Catalog\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * A published product's catalog data changed (name, taxonomy, attributes, …).
 * Search and other read models reconcile from this. Draft edits do not emit —
 * only changes to a live product matter downstream.
 */
final class ProductUpdated extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $productUuid,
        private readonly array $changed,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'catalog.product_updated';
    }

    public function payload(): array
    {
        return [
            'product_uuid' => $this->productUuid,
            'changed'      => array_values($this->changed),
        ];
    }
}
