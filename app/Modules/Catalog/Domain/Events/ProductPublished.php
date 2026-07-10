<?php

namespace App\Modules\Catalog\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * A product became live in the catalog. Downstream contexts react without
 * Catalog knowing them — Search (Phase 9) reindexes, Pricing/Inventory may
 * provision defaults, Analytics counts. Carries only identity + taxonomy, never
 * commerce facts.
 */
final class ProductPublished extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $productUuid,
        private readonly string $slug,
        private readonly ?string $categoryUuid,
        private readonly string $name,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'catalog.product_published';
    }

    public function payload(): array
    {
        return [
            'product_uuid'  => $this->productUuid,
            'slug'          => $this->slug,
            'category_uuid' => $this->categoryUuid,
            'name'          => $this->name,
        ];
    }
}
