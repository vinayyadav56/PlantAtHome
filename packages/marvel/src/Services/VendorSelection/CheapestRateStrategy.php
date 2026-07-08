<?php

namespace Marvel\Services\VendorSelection;

/**
 * DEFAULT strategy. candidatesFor() already sorts candidates LOCAL-tier-first, then
 * by weighted score desc, then cheapest vendor_rate — i.e. it maximises the platform
 * margin (customer price is fixed per city) while preferring fast local fulfilment.
 * So "cheapest" simply honours that existing order → behaviour is byte-identical to
 * before the Strategy Pattern was introduced.
 */
class CheapestRateStrategy implements VendorSelectionStrategy
{
    public function key(): string
    {
        return 'cheapest';
    }

    public function rank(array $candidates, array $context): array
    {
        return $candidates; // already sorted by candidatesFor()
    }

    public function select(array $candidates, array $context): ?array
    {
        return $candidates[0] ?? null;
    }
}
