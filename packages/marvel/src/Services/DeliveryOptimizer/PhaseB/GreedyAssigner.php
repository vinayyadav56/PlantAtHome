<?php

namespace Marvel\Services\DeliveryOptimizer\PhaseB;

use Marvel\Services\DeliveryOptimizer\Dto\Candidate;
use Marvel\Services\DeliveryOptimizer\Dto\CartItem;

/**
 * Greedy least-marginal-cost assignment. Items are processed MOST-CONSTRAINED-FIRST
 * (fewest viable legs) so the forced placements happen before the flexible ones, which
 * minimises regret. Each item lands on the leg whose marginal cost is smallest — and a
 * leg already opened by an earlier item from the same (vendor, rail) has a marginal of
 * ~0 (only a weight-slab bump), which is how consolidation falls out for free.
 */
final class GreedyAssigner
{
    public function __construct(private CostModel $costModel)
    {
    }

    /**
     * @param CartItem[] $items
     * @param array<string, Candidate[]> $itemCandidates itemKey => candidates (deduped per leg)
     */
    public function assign(array $items, array $itemCandidates): PlanState
    {
        $state = new PlanState();
        foreach ($items as $item) {
            $state->registerItem($item, $itemCandidates[$item->key()] ?? []);
        }

        // Most-constrained-first; stable tie-break on itemKey for determinism.
        $order = array_keys($state->itemViable);
        usort($order, function ($a, $b) use ($state) {
            $na = count($state->itemViable[$a]);
            $nb = count($state->itemViable[$b]);
            if ($na !== $nb) {
                return $na <=> $nb;
            }
            return strcmp($a, $b);
        });

        foreach ($order as $itemKey) {
            $bestLeg = null;
            $bestMarginal = INF;
            foreach ($state->itemViable[$itemKey] as $legKey => $cand) {
                $marginal = $this->marginalCost($state, $itemKey, $legKey, $cand);
                if ($marginal < $bestMarginal - 1e-9) {
                    $bestMarginal = $marginal;
                    $bestLeg = $legKey;
                }
            }
            if ($bestLeg !== null) {
                $state->place($itemKey, $bestLeg);
            }
        }

        return $state;
    }

    /**
     * Marginal cost of placing $itemKey onto $legKey given the current assignment:
     *   Δ(leg delivery fee) + product cost on this vendor + SLA penalty.
     * The first term is ~0 when the leg already ships this (vendor, rail).
     */
    public function marginalCost(PlanState $state, string $itemKey, string $legKey, Candidate $cand): float
    {
        $before = $state->legFee($legKey, $this->costModel);
        // Tentatively add the item, measure, then revert (item not yet placed during greedy).
        $state->legs[$legKey]['items'][$itemKey] = true;
        $after = $state->legFee($legKey, $this->costModel);
        unset($state->legs[$legKey]['items'][$itemKey]);

        $deltaFee = $after - $before;
        $product = $this->costModel->productCost($cand->sellingPrice, $state->cart[$itemKey]->qty);
        $sla = $this->costModel->slaPenalty($cand->etaDays);

        return $deltaFee + $product + $sla;
    }
}
