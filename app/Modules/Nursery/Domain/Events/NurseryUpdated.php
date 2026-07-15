<?php

namespace App\Modules\Nursery\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * A nursery's profile or lifecycle state changed (edit, suspend). The legacy
 * projection mirrors name/slug/description/is_active onto the `shops` row.
 */
final class NurseryUpdated extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $nurseryUuid,
        private readonly ?int $legacyId,
        private readonly string $name,
        private readonly ?string $slug,
        private readonly ?string $description,
        private readonly string $status,
        private readonly ?string $actorUuid,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'nursery.updated';
    }

    public function payload(): array
    {
        return [
            'nursery_uuid' => $this->nurseryUuid,
            'legacy_id'    => $this->legacyId,
            'name'         => $this->name,
            'slug'         => $this->slug,
            'description'  => $this->description,
            'status'       => $this->status,
            'actor_uuid'   => $this->actorUuid,
        ];
    }
}
