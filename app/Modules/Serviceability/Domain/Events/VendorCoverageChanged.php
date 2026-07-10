<?php

namespace App\Modules\Serviceability\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * A vendor's city coverage changed (added, updated, or removed). Search
 * (Phase 9) reindexes per-city availability; catalog/config caches that are
 * city-scoped invalidate.
 */
final class VendorCoverageChanged extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $nurseryId,
        private readonly string $cityUuid,
        private readonly bool $active,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'serviceability.vendor_coverage_changed';
    }

    public function payload(): array
    {
        return ['nursery_id' => $this->nurseryId, 'city_uuid' => $this->cityUuid, 'active' => $this->active];
    }
}
