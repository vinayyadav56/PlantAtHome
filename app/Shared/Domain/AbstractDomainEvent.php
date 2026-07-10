<?php

namespace App\Shared\Domain;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Base class for domain events: stamps event_id (uuid) + occurred_at at
 * construction. Subclasses supply eventName() and payload(). A subclass that
 * needs to reconstruct an event from stored data (the relay) uses withMetadata().
 */
abstract class AbstractDomainEvent implements DomainEvent
{
    private string $eventId;
    private string $occurredAt;
    private int $version;

    public function __construct(int $version = 1)
    {
        $this->eventId = (string) Str::uuid();
        $this->occurredAt = Carbon::now()->toIso8601String();
        $this->version = $version;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function occurredAt(): string
    {
        return $this->occurredAt;
    }

    public function version(): int
    {
        return $this->version;
    }

    /** Rehydrate the envelope metadata (used when replaying from the outbox). */
    public function withMetadata(string $eventId, string $occurredAt, int $version): static
    {
        $clone = clone $this;
        $clone->eventId = $eventId;
        $clone->occurredAt = $occurredAt;
        $clone->version = $version;

        return $clone;
    }
}
