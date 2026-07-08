<?php

namespace Marvel\Services\VendorSelection;

/**
 * Base for strategies that keep the platform's hard "fast local first" rule (a LOCAL
 * candidate always outranks a COURIER one) and then order within a tier by a
 * strategy-specific comparator. select() = the first of rank().
 */
abstract class AbstractTieredStrategy implements VendorSelectionStrategy
{
    /** Comparator applied WITHIN a fulfilment tier (return <0 if $a is better). */
    abstract protected function compareWithinTier(array $a, array $b, array $context): int;

    public function rank(array $candidates, array $context): array
    {
        usort($candidates, function ($a, $b) use ($context) {
            $ta = ($a['fulfillment_mode'] ?? '') === 'local' ? 0 : 1;
            $tb = ($b['fulfillment_mode'] ?? '') === 'local' ? 0 : 1;
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }
            $c = $this->compareWithinTier($a, $b, $context);
            if ($c !== 0) {
                return $c;
            }
            // Deterministic final tiebreak: cheaper vendor rate (higher platform margin).
            return ($a['vendor_rate'] ?? INF) <=> ($b['vendor_rate'] ?? INF);
        });
        return $candidates;
    }

    public function select(array $candidates, array $context): ?array
    {
        $ranked = $this->rank($candidates, $context);
        return $ranked[0] ?? null;
    }
}
