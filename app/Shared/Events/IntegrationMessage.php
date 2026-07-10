<?php

namespace App\Shared\Events;

/**
 * The immutable message a subscriber receives from the relay. It is the
 * serialized form of a DomainEvent read back from the outbox — subscribers get
 * data (event_id, name, payload), never the producer's in-memory object, which
 * keeps modules decoupled and makes the in-process bus swappable for a network
 * bus later with no consumer changes (Section 14).
 */
final class IntegrationMessage
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventName,
        public readonly int $version,
        public readonly array $payload,
        public readonly string $occurredAt,
    ) {
    }

    public static function fromOutboxRow(object $row): self
    {
        return new self(
            eventId: $row->event_id,
            eventName: $row->event_name,
            version: (int) $row->version,
            payload: json_decode($row->payload ?? '{}', true) ?: [],
            occurredAt: (string) $row->occurred_at,
        );
    }
}
