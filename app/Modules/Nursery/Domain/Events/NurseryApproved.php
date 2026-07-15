<?php

namespace App\Modules\Nursery\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * A nursery was approved (status → active). The legacy projection flips the
 * `shops` row to is_active and syncs the commission; Notifications may welcome
 * the vendor. legacy_id rides in the payload so the projection needs no lookup.
 */
final class NurseryApproved extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $nurseryUuid,
        private readonly ?int $legacyId,
        private readonly string $name,
        private readonly ?string $slug,
        private readonly ?string $description,
        private readonly string $status,
        private readonly ?float $commissionRate,
        private readonly ?string $actorUuid,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'nursery.approved';
    }

    public function payload(): array
    {
        return [
            'nursery_uuid'    => $this->nurseryUuid,
            'legacy_id'       => $this->legacyId,
            'name'            => $this->name,
            'slug'            => $this->slug,
            'description'     => $this->description,
            'status'          => $this->status,
            'commission_rate' => $this->commissionRate,
            'actor_uuid'      => $this->actorUuid,
        ];
    }
}
