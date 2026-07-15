<?php

namespace App\Modules\Nursery\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * A nursery's commission rate changed. The legacy projection writes it back to
 * `balances.admin_commission_rate`; Pricing recomputes city prices downstream.
 */
final class CommissionChanged extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $nurseryUuid,
        private readonly ?int $legacyId,
        private readonly float $commissionRate,
        private readonly ?string $actorUuid,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'nursery.commission_changed';
    }

    public function payload(): array
    {
        return [
            'nursery_uuid'    => $this->nurseryUuid,
            'legacy_id'       => $this->legacyId,
            'commission_rate' => $this->commissionRate,
            'actor_uuid'      => $this->actorUuid,
        ];
    }
}
