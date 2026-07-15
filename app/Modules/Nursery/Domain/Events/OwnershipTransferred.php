<?php

namespace App\Modules\Nursery\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * A nursery changed hands: owner_user_uuid switched and the new owner's
 * identity scope now points at this nursery. Audit + notification trigger.
 */
final class OwnershipTransferred extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $nurseryUuid,
        private readonly ?int $legacyId,
        private readonly ?string $previousOwnerUuid,
        private readonly string $newOwnerUuid,
        private readonly ?string $actorUuid,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'nursery.ownership_transferred';
    }

    public function payload(): array
    {
        return [
            'nursery_uuid'        => $this->nurseryUuid,
            'legacy_id'           => $this->legacyId,
            'previous_owner_uuid' => $this->previousOwnerUuid,
            'new_owner_uuid'      => $this->newOwnerUuid,
            'actor_uuid'          => $this->actorUuid,
        ];
    }
}
