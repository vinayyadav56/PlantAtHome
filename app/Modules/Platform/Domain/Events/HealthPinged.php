<?php

namespace App\Modules\Platform\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * Emitted when the platform receives a ping. It exists to exercise the outbox
 * end-to-end in Phase 0 (a real domain event, published through the outbox,
 * delivered to a subscriber) — later modules follow this exact shape.
 */
final class HealthPinged extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $source,
        private readonly string $note = '',
    ) {
        parent::__construct(version: 1);
    }

    public function eventName(): string
    {
        return 'platform.health_pinged';
    }

    public function payload(): array
    {
        return [
            'source' => $this->source,
            'note'   => $this->note,
        ];
    }
}
