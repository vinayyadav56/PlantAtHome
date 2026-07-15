<?php

namespace App\Modules\Nursery\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * A nursery was created by an admin. The legacy `shops` projection is written
 * inline in the creating transaction (same DB), so this event only announces
 * the fact — Notifications/analytics may subscribe later.
 */
final class NurseryCreated extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $nurseryUuid,
        private readonly ?int $legacyId,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'nursery.created';
    }

    public function payload(): array
    {
        return [
            'nursery_uuid' => $this->nurseryUuid,
            'legacy_id'    => $this->legacyId,
        ];
    }
}
