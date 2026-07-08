<?php

namespace Marvel\Services\VendorSelection;

/** Prefer the best-rated vendor (service quality). Unrated vendors sort last. */
class HighestRatingStrategy extends AbstractTieredStrategy
{
    public function key(): string
    {
        return 'rating';
    }

    protected function compareWithinTier(array $a, array $b, array $context): int
    {
        $ra = $a['rating'] !== null ? (float) $a['rating'] : -1.0;
        $rb = $b['rating'] !== null ? (float) $b['rating'] : -1.0;
        return $rb <=> $ra; // higher rating first
    }
}
