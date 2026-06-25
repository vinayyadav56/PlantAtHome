<?php

namespace Marvel\Services\DeliveryOptimizer\PhaseB;

use Marvel\Services\DeliveryOptimizer\Dto\Candidate;

/**
 * Turns raw per-item candidates into the candidate-shipment view the assigner consumes:
 * each item's candidates deduped to one per (vendor, rail) leg (keeping the best score),
 * since an item only needs its single cheapest way onto any given leg.
 */
final class ShipmentGrouper
{
    /**
     * @param array<string, Candidate[]> $rawByItem itemKey => candidates
     * @return array<string, Candidate[]> itemKey => candidates (<=1 per legKey)
     */
    public function dedupePerLeg(array $rawByItem): array
    {
        $out = [];
        foreach ($rawByItem as $itemKey => $cands) {
            $byLeg = [];
            foreach ($cands as $c) {
                $lk = $c->legKey();
                if (!isset($byLeg[$lk]) || $c->score > $byLeg[$lk]->score) {
                    $byLeg[$lk] = $c;
                }
            }
            $out[$itemKey] = array_values($byLeg);
        }
        return $out;
    }
}
