<?php

namespace App\Shared\Domain;

/**
 * Base domain entity — an object with a stable identity (equality is by id,
 * not by attribute values). Framework-agnostic: knows nothing about Eloquent
 * or HTTP (dependency rule, Section 1).
 */
abstract class Entity
{
    public function __construct(protected string|int $id)
    {
    }

    public function id(): string|int
    {
        return $this->id;
    }

    public function equals(?Entity $other): bool
    {
        return $other instanceof static && (string) $other->id() === (string) $this->id;
    }
}
