<?php

namespace App\Shared\Domain;

/**
 * An aggregate root is the single entry point to a consistency boundary. It
 * records domain events as its state changes; the application layer releases
 * them and hands them to the EventPublisher (which writes them to the outbox
 * inside the same DB transaction — Section 9). Events are pull-once: releasing
 * them clears the buffer.
 */
abstract class AggregateRoot extends Entity
{
    /** @var DomainEvent[] */
    private array $domainEvents = [];

    protected function recordThat(DomainEvent $event): void
    {
        $this->domainEvents[] = $event;
    }

    /** @return DomainEvent[] */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }

    public function hasRecordedEvents(): bool
    {
        return $this->domainEvents !== [];
    }
}
