<?php

namespace App\Modules\Rules\Domain;

use Illuminate\Support\Arr;

/**
 * The bag of facts a rule evaluates against, assembled per evaluation by the
 * caller (e.g. Configuration assembles product.category, variant.size,
 * plant.is_fragile, selection.options, nursery.id, city.id, cart.total).
 * Facts are read by dot-path so conditions reference them declaratively.
 */
final class RuleContext
{
    /** @param array<string,mixed> $facts */
    public function __construct(private array $facts = [])
    {
    }

    public function set(string $fact, mixed $value): self
    {
        Arr::set($this->facts, $fact, $value);

        return $this;
    }

    public function get(string $fact): mixed
    {
        return Arr::get($this->facts, $fact);
    }

    public function has(string $fact): bool
    {
        return Arr::has($this->facts, $fact) && $this->get($fact) !== null;
    }

    public function all(): array
    {
        return $this->facts;
    }
}
