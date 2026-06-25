<?php

namespace Tests\Unit\DeliveryOptimizer;

use Marvel\Services\DeliveryOptimizer\PhaseB\CostModel;
use Marvel\Services\DeliveryOptimizer\PhaseB\PlanState;
use PHPUnit\Framework\TestCase;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeConfig;
use Tests\Unit\DeliveryOptimizer\Fakes\MakesOptimizerFixtures;

final class CostModelTest extends TestCase
{
    use MakesOptimizerFixtures;

    private function model(array $cfg = []): CostModel
    {
        return new CostModel(new FakeConfig($cfg));
    }

    public function test_leg_fee_is_base_plus_per_kg_over_ceil_kg(): void
    {
        $m = $this->model();
        $this->assertEqualsWithDelta(60.0, $m->legFee(50, 10, 500), 0.001);   // 500g billed at 1 kg minimum
        $this->assertEqualsWithDelta(70.0, $m->legFee(50, 10, 1500), 0.001);  // ceil(1.5)=2 kg
        $this->assertEqualsWithDelta(80.0, $m->legFee(50, 10, 3000), 0.001);  // 3 kg
    }

    public function test_sla_penalty_only_past_target(): void
    {
        $m = $this->model(['slaPenalty' => 5.0, 'targetSla' => 3]);
        $this->assertEqualsWithDelta(0.0, $m->slaPenalty(2), 0.001);
        $this->assertEqualsWithDelta(0.0, $m->slaPenalty(3), 0.001);
        $this->assertEqualsWithDelta(10.0, $m->slaPenalty(5), 0.001); // 2 days over × 5
    }

    public function test_product_cost(): void
    {
        $m = $this->model();
        $this->assertEqualsWithDelta(300.0, $m->productCost(100.0, 3), 0.001);
        $this->assertEqualsWithDelta(0.0, $m->productCost(null, 2), 0.001);
    }

    public function test_plan_total_counts_each_leg_delivery_fee_once(): void
    {
        $m = $this->model(['slaPenalty' => 0.0]);
        $state = new PlanState();

        // Two items, same vendor+rail → ONE leg, ONE base fee.
        $state->registerItem($this->item(11), [$this->cand(11, 1, 'local', 50, 2, 100.0)]);
        $state->registerItem($this->item(12), [$this->cand(12, 1, 'local', 50, 2, 100.0)]);
        $state->place('11:0', '1|INSTANT');
        $state->place('12:0', '1|INSTANT');

        // 50 (delivery ONCE) + 100 + 100 (products) = 250 — NOT 300 (fee double-counted).
        $this->assertEqualsWithDelta(250.0, $state->totalCost($m), 0.001);
    }
}
