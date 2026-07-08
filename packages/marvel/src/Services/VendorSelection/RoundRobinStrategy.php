<?php

namespace Marvel\Services\VendorSelection;

/**
 * Spread orders across eligible vendors. Stateless + deterministic: rotate the
 * (score-sorted) candidate list by product_id so different products favour different
 * vendors, without needing a persisted cursor. LOCAL-first tiering still applies via
 * the score order candidatesFor produced.
 */
class RoundRobinStrategy implements VendorSelectionStrategy
{
    public function key(): string
    {
        return 'round_robin';
    }

    public function rank(array $candidates, array $context): array
    {
        $n = count($candidates);
        if ($n <= 1) {
            return $candidates;
        }
        $offset = ((int) ($context['product_id'] ?? 0)) % $n;
        return array_merge(array_slice($candidates, $offset), array_slice($candidates, 0, $offset));
    }

    public function select(array $candidates, array $context): ?array
    {
        return $this->rank($candidates, $context)[0] ?? null;
    }
}
