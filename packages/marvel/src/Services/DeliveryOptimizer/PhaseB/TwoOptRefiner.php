<?php

namespace Marvel\Services\DeliveryOptimizer\PhaseB;

/**
 * Bounded local search over the greedy plan. Repeatedly relocates an item to a cheaper
 * viable leg whenever that strictly lowers the plan total — which includes "evacuating"
 * the last item out of a 1-item leg to delete its whole base fee. Swaps are covered by
 * the move neighbourhood applied both directions across passes.
 *
 * Hard-bounded by a wall-clock budget (hrtime, monotonic) so the worst case is O(budget),
 * never the O(P^2)-per-pass of an unbounded 2-opt — protecting the <150ms p99.
 */
final class TwoOptRefiner
{
    public function __construct(private CostModel $costModel)
    {
    }

    public function refine(PlanState $state, int $timeBudgetMs): PlanState
    {
        $deadline = $this->nowMs() + max(0, $timeBudgetMs);
        $improved = true;

        while ($improved && $this->nowMs() < $deadline) {
            $improved = false;
            foreach (array_keys($state->cart) as $itemKey) {
                $currentLeg = $state->assignment[$itemKey] ?? null;
                if ($currentLeg === null) {
                    continue;
                }
                foreach ($state->itemViable[$itemKey] as $legKey => $cand) {
                    if ($legKey === $currentLeg) {
                        continue;
                    }
                    if ($this->nowMs() >= $deadline) {
                        return $state;
                    }
                    $delta = $this->moveDelta($state, $itemKey, $currentLeg, $legKey);
                    if ($delta < -1e-9) {
                        $state->unassign($itemKey);
                        $state->place($itemKey, $legKey);
                        $currentLeg = $legKey;
                        $improved = true;
                    }
                }
            }
        }

        return $state;
    }

    /**
     * Cost delta of moving $itemKey from $from to $to, computed locally over the two
     * affected legs (the rest of the plan is unchanged).
     */
    private function moveDelta(PlanState $state, string $itemKey, string $from, string $to): float
    {
        $candFrom = $state->itemViable[$itemKey][$from] ?? null;
        $candTo = $state->itemViable[$itemKey][$to] ?? null;
        if ($candFrom === null || $candTo === null) {
            return INF; // not a legal move
        }
        $qty = $state->cart[$itemKey]->qty;

        $feeFromBefore = $state->legFee($from, $this->costModel);
        $feeToBefore = $state->legFee($to, $this->costModel);

        // Simulate the move, measure, then revert exactly.
        unset($state->legs[$from]['items'][$itemKey]);
        $state->legs[$to]['items'][$itemKey] = true;
        $feeFromAfter = $state->legFee($from, $this->costModel);
        $feeToAfter = $state->legFee($to, $this->costModel);
        unset($state->legs[$to]['items'][$itemKey]);
        $state->legs[$from]['items'][$itemKey] = true;

        $deltaFee = ($feeFromAfter - $feeFromBefore) + ($feeToAfter - $feeToBefore);
        $deltaProduct = $this->costModel->productCost($candTo->sellingPrice, $qty)
            - $this->costModel->productCost($candFrom->sellingPrice, $qty);
        $deltaSla = $this->costModel->slaPenalty($candTo->etaDays)
            - $this->costModel->slaPenalty($candFrom->etaDays);

        return $deltaFee + $deltaProduct + $deltaSla;
    }

    private function nowMs(): float
    {
        return hrtime(true) / 1e6;
    }
}
