<?php

namespace App\Shared\Events;

use App\Shared\Domain\DomainEvent;

/**
 * Publishes domain events. The default implementation writes them to the
 * transactional outbox INSIDE the caller's DB transaction, so an event is
 * persisted iff its business change commits — no lost or phantom events
 * (Section 9). A relay worker later delivers them to subscribers.
 */
interface EventPublisher
{
    public function publish(DomainEvent ...$events): void;
}
