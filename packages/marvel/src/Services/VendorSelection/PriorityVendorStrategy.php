<?php

namespace Marvel\Services\VendorSelection;

/** Prefer the highest admin-assigned vendor priority score (business preference). */
class PriorityVendorStrategy extends AbstractTieredStrategy
{
    public function key(): string
    {
        return 'priority';
    }

    protected function compareWithinTier(array $a, array $b, array $context): int
    {
        // Higher priority first.
        return (int) ($b['priority'] ?? 0) <=> (int) ($a['priority'] ?? 0);
    }
}
