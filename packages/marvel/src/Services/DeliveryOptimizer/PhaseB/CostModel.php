<?php

namespace Marvel\Services\DeliveryOptimizer\PhaseB;

use Marvel\Services\DeliveryOptimizer\Contracts\OptimizerConfigInterface;

/**
 * The objective. Isolated here so a future exact ILP/MILP solver can swap in behind it
 * without touching the search. The plan total is:
 *
 *     total = Σ_legs legFee(leg) + Σ_items productCost(item) + Σ_items slaPenalty(item)
 *
 * The consolidation win lives in the marginal: adding a 2nd item to a leg that already
 * ships the same (vendor, rail) only bumps the per-kg term — the flat base is already
 * paid. Opening a NEW leg costs a full base fee. greedy/2-opt chase exactly that gap.
 *
 * legFee uses vendor_shipping_rates primitives (base + per-kg) as the search-time
 * ESTIMATE; firm carrier quotes refine the chosen legs at checkout (never during search).
 */
final class CostModel
{
    public function __construct(private OptimizerConfigInterface $config)
    {
    }

    /** Estimated fee for a leg carrying `weightG` grams: flat base + per-kg over ceil(kg). */
    public function legFee(float $baseCost, float $perKgCost, int $weightG): float
    {
        $kg = (int) ceil(max(1, $weightG) / 1000);
        return $baseCost + ($perKgCost * $kg);
    }

    /** Late-delivery penalty: days over target SLA × per-day penalty (0 when on time). */
    public function slaPenalty(int $etaDays): float
    {
        $over = max(0, $etaDays - $this->config->targetSlaDays());
        return $over * $this->config->slaPenaltyPerDay();
    }

    /** Vendor's selling price for the line (qty × unit). */
    public function productCost(?float $sellingPrice, int $qty): float
    {
        return (float) ($sellingPrice ?? 0) * max(1, $qty);
    }
}
