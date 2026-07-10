<?php

namespace App\Modules\Configuration\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * The configuration model changed (a group/option/assignment/compatibility/
 * enablement write). Drives cache invalidation of resolved configurations and
 * lets Search (Phase 9) reindex. Coarse by design — carries the changed entity
 * type + id so consumers can decide how much to invalidate.
 */
final class ConfigurationChanged extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $entity,      // group | option | assignment | compatibility | enablement
        private readonly string $entityUuid,
        private readonly ?string $nurseryId = null,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'configuration.changed';
    }

    public function payload(): array
    {
        return [
            'entity'      => $this->entity,
            'entity_uuid' => $this->entityUuid,
            'nursery_id'  => $this->nurseryId,
        ];
    }
}
