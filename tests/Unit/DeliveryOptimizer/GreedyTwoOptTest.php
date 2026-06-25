<?php

namespace Tests\Unit\DeliveryOptimizer;

use Marvel\Services\DeliveryOptimizer\DeliveryOptimizerService;
use Marvel\Services\DeliveryOptimizer\Dto\UserLocation;
use Marvel\Services\DeliveryOptimizer\PhaseB\CostModel;
use Marvel\Services\DeliveryOptimizer\PhaseB\PlanState;
use Marvel\Services\DeliveryOptimizer\PhaseB\TwoOptRefiner;
use PHPUnit\Framework\TestCase;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeCandidateProvider;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeConfig;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeQuoteClient;
use Tests\Unit\DeliveryOptimizer\Fakes\MakesOptimizerFixtures;

final class GreedyTwoOptTest extends TestCase
{
    use MakesOptimizerFixtures;

    public function test_multi_candidate_item_joins_the_already_open_shared_leg(): void
    {
        // Seed S only at D; Tool T can go to D OR a fresh vendor E. T should join D for free.
        $cp = new FakeCandidateProvider([
            '22:0' => [$this->cand(22, 4, 'courier', 80, 5)],                 // S @ D
            '23:0' => [
                $this->cand(23, 4, 'courier', 80, 5, 100.0, 0.0, 1.0),         // T @ D (join)
                $this->cand(23, 5, 'courier', 80, 5, 100.0, 0.0, 0.9),         // T @ E (new leg)
            ],
        ]);
        $service = new DeliveryOptimizerService($cp, new FakeQuoteClient(), new FakeConfig());
        $result = $service->optimizeCart([$this->item(22), $this->item(23)], new UserLocation('Gurugram', '122001'));

        $this->assertCount(1, $result->shipments, 'Tool consolidates onto the seed leg');
        $this->assertSame(4, $result->shipments[0]->vendorId);
        $this->assertEqualsWithDelta(80.0, $result->internalTrueCost, 0.001); // one fee, not two
    }

    public function test_two_opt_evacuates_a_suboptimal_solo_leg(): void
    {
        // Hand-build a deliberately suboptimal plan: X parked alone on v1 (fee 100) while it
        // could ride v2 with Y for free. refine() must relocate X and delete v1's fee.
        $cfg = new FakeConfig(['slaPenalty' => 0.0, 'timeBudgetMs' => 50]);
        $cost = new CostModel($cfg);
        $state = new PlanState();

        $state->registerItem($this->item(1), [
            $this->cand(1, 1, 'local', 100, 2, 100.0), // X @ v1
            $this->cand(1, 2, 'local', 100, 2, 100.0), // X @ v2
        ]);
        $state->registerItem($this->item(2), [
            $this->cand(2, 2, 'local', 100, 2, 100.0), // Y @ v2 only
        ]);
        $state->place('1:0', '1|INSTANT'); // X solo on v1 (bad)
        $state->place('2:0', '2|INSTANT'); // Y on v2

        $before = $state->totalCost($cost);
        (new TwoOptRefiner($cost))->refine($state, 50);
        $after = $state->totalCost($cost);

        $this->assertEqualsWithDelta(100.0, $before - $after, 0.001, '2-opt removes the redundant v1 fee');
        $this->assertSame('2|INSTANT', $state->assignment['1:0'], 'X moved to the shared leg');
        $this->assertCount(1, $state->activeLegs());
    }

    public function test_two_opt_is_stable_on_an_already_optimal_plan(): void
    {
        $cp = new FakeCandidateProvider([
            '11:0' => [$this->cand(11, 1, 'local', 50, 2)],
            '12:0' => [$this->cand(12, 1, 'local', 50, 2)],
        ]);
        $service = new DeliveryOptimizerService($cp, new FakeQuoteClient(), new FakeConfig());
        $result = $service->optimizeCart([$this->item(11), $this->item(12)], new UserLocation('Gurugram', '122001'));

        $this->assertCount(1, $result->shipments);
        $this->assertEqualsWithDelta(50.0, $result->internalTrueCost, 0.001);
    }

    public function test_unfulfillable_items_are_excluded_not_fatal(): void
    {
        $cp = new FakeCandidateProvider([
            '11:0' => [$this->cand(11, 1, 'local', 50, 2)],
            '99:0' => [], // nobody can fulfil
        ]);
        $service = new DeliveryOptimizerService($cp, new FakeQuoteClient(), new FakeConfig());
        $result = $service->optimizeCart([$this->item(11), $this->item(99)], new UserLocation('Gurugram', '122001'));

        $this->assertCount(1, $result->shipments);
        $this->assertCount(1, $result->unfulfillableItems);
        $this->assertSame(99, $result->unfulfillableItems[0]->productId);
    }
}
