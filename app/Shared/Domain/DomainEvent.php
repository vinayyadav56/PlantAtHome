<?php

namespace App\Shared\Domain;

/**
 * A domain event — a fact that happened in the domain. Carries a stable
 * event_id (for idempotent consumers), an occurred_at, a schema version, a
 * name, and a serializable payload (Section 9 of the architecture plan).
 * Events are the ONLY sanctioned cross-module communication besides service
 * interfaces — never reach into another module's tables.
 */
interface DomainEvent
{
    /** Globally-unique id; consumers dedupe on this (at-least-once delivery). */
    public function eventId(): string;

    /** Dotted name, e.g. "platform.health_pinged". */
    public function eventName(): string;

    /** ISO-8601 timestamp string. */
    public function occurredAt(): string;

    /** Schema version of this event's payload. */
    public function version(): int;

    /** JSON-serializable payload. */
    public function payload(): array;
}
