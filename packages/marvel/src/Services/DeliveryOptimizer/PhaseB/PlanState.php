<?php

namespace Marvel\Services\DeliveryOptimizer\PhaseB;

use Marvel\Services\DeliveryOptimizer\Dto\Candidate;
use Marvel\Services\DeliveryOptimizer\Dto\CartItem;

/**
 * The mutable assignment state shared by GreedyAssigner and TwoOptRefiner: which items
 * sit on which (vendor, rail) legs, plus the per-item viable legs and cart weights. Kept
 * deliberately dumb (no I/O) so the search is pure CPU and trivially testable.
 */
final class PlanState
{
    /** @var array<string, array{vendorId:int, rail:string, baseCost:float, perKgCost:float, items: array<string,bool>}> */
    public array $legs = [];

    /** @var array<string, string> itemKey => legKey */
    public array $assignment = [];

    /** @var array<string, array<string, Candidate>> itemKey => legKey => Candidate */
    public array $itemViable = [];

    /** @var array<string, CartItem> itemKey => CartItem */
    public array $cart = [];

    public function registerItem(CartItem $item, array $candidates): void
    {
        $ik = $item->key();
        $this->cart[$ik] = $item;
        if (!isset($this->itemViable[$ik])) {
            $this->itemViable[$ik] = [];
        }
        foreach ($candidates as $c) {
            /** @var Candidate $c */
            $lk = $c->legKey();
            // Keep the highest-scored candidate per leg (dedupe defensive).
            if (!isset($this->itemViable[$ik][$lk]) || $c->score > $this->itemViable[$ik][$lk]->score) {
                $this->itemViable[$ik][$lk] = $c;
            }
            $this->ensureLeg($c);
        }
    }

    public function ensureLeg(Candidate $c): void
    {
        $lk = $c->legKey();
        if (!isset($this->legs[$lk])) {
            $this->legs[$lk] = [
                'vendorId'  => $c->vendorId,
                'rail'      => $c->rail,
                'baseCost'  => $c->baseCost,
                'perKgCost' => $c->perKgCost,
                'items'     => [],
            ];
        }
    }

    public function legWeightG(string $legKey): int
    {
        $w = 0;
        foreach (array_keys($this->legs[$legKey]['items']) as $ik) {
            $w += $this->cart[$ik]->totalWeightG();
        }
        return $w;
    }

    public function legFee(string $legKey, CostModel $costModel): float
    {
        $leg = $this->legs[$legKey];
        if (empty($leg['items'])) {
            return 0.0;
        }
        return $costModel->legFee($leg['baseCost'], $leg['perKgCost'], $this->legWeightG($legKey));
    }

    public function place(string $itemKey, string $legKey): void
    {
        $this->assignment[$itemKey] = $legKey;
        $this->legs[$legKey]['items'][$itemKey] = true;
    }

    public function unassign(string $itemKey): void
    {
        $lk = $this->assignment[$itemKey] ?? null;
        if ($lk !== null) {
            unset($this->legs[$lk]['items'][$itemKey], $this->assignment[$itemKey]);
        }
    }

    public function candidate(string $itemKey, string $legKey): ?Candidate
    {
        return $this->itemViable[$itemKey][$legKey] ?? null;
    }

    /** @return array<string, array{vendorId:int, rail:string, baseCost:float, perKgCost:float, items: array<string,bool>}> */
    public function activeLegs(): array
    {
        return array_filter($this->legs, fn ($l) => !empty($l['items']));
    }

    public function totalCost(CostModel $costModel): float
    {
        $total = 0.0;
        foreach ($this->legs as $lk => $leg) {
            if (empty($leg['items'])) {
                continue;
            }
            $total += $this->legFee($lk, $costModel);
            foreach (array_keys($leg['items']) as $ik) {
                $cand = $this->itemViable[$ik][$lk] ?? null;
                if ($cand === null) {
                    continue;
                }
                $total += $costModel->productCost($cand->sellingPrice, $this->cart[$ik]->qty);
                $total += $costModel->slaPenalty($cand->etaDays);
            }
        }
        return $total;
    }
}
