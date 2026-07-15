<?php

namespace App\Modules\Nursery\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * An admin acted on a withdrawal request (approve/reject/on_hold/processing).
 * Approval also debited the nursery balance and wrote a ledger entry — this
 * event is the audit ping; Notifications tell the vendor.
 */
final class WithdrawalDecided extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $withdrawalUuid,
        private readonly string $nurseryUuid,
        private readonly ?int $legacyId,
        private readonly string $status,
        private readonly float $amount,
        private readonly ?string $actorUuid,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'nursery.withdrawal_decided';
    }

    public function payload(): array
    {
        return [
            'withdrawal_uuid' => $this->withdrawalUuid,
            'nursery_uuid'    => $this->nurseryUuid,
            'legacy_id'       => $this->legacyId,
            'status'          => $this->status,
            'amount'          => $this->amount,
            'actor_uuid'      => $this->actorUuid,
        ];
    }
}
