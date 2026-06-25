<?php

namespace Tests\Unit\DeliveryOptimizer;

use Marvel\Services\DeliveryOptimizer\DeliveryOptimizerService;
use Marvel\Services\DeliveryOptimizer\Dto\UserLocation;
use PHPUnit\Framework\TestCase;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeCandidateProvider;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeConfig;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeQuoteClient;
use Tests\Unit\DeliveryOptimizer\Fakes\MakesOptimizerFixtures;

final class IncrementalRecomputeTest extends TestCase
{
    use MakesOptimizerFixtures;

    private function service(): DeliveryOptimizerService
    {
        $cp = new FakeCandidateProvider([
            '22:0' => [$this->cand(22, 4, 'courier', 80, 5)],  // S @ D
            '23:0' => [$this->cand(23, 4, 'courier', 80, 5)],  // T @ D (joins)
            '31:0' => [$this->cand(31, 7, 'local', 50, 2)],    // new item @ fresh vendor
        ]);
        return new DeliveryOptimizerService($cp, new FakeQuoteClient(), new FakeConfig());
    }

    private function loc(): UserLocation
    {
        return new UserLocation('Gurugram', '122001');
    }

    public function test_adding_an_item_to_an_existing_vendor_does_not_open_a_new_leg(): void
    {
        $service = $this->service();
        $state = $service->solve([$this->item(22)], $this->loc())['state'];
        $this->assertCount(1, $state->activeLegs());

        $service->incrementalAdd($state, $this->item(23), $this->loc());

        $this->assertCount(1, $state->activeLegs(), 'Tool joins the seed leg — no new leg');
        $this->assertArrayHasKey('23:0', $state->assignment);
        $this->assertSame('4|COURIER', $state->assignment['23:0']);
    }

    public function test_adding_a_new_vendor_item_opens_a_second_leg(): void
    {
        $service = $this->service();
        $state = $service->solve([$this->item(22)], $this->loc())['state'];

        $service->incrementalAdd($state, $this->item(31), $this->loc());

        $this->assertCount(2, $state->activeLegs());
    }

    public function test_removing_an_item_frees_its_leg(): void
    {
        $service = $this->service();
        $state = $service->solve([$this->item(22)], $this->loc())['state'];
        $service->incrementalAdd($state, $this->item(31), $this->loc());
        $this->assertCount(2, $state->activeLegs());

        $service->incrementalRemove($state, '31:0');

        $this->assertCount(1, $state->activeLegs(), 'Emptied leg disappears');
        $this->assertArrayNotHasKey('31:0', $state->assignment);
    }

    public function test_unfulfillable_added_item_leaves_plan_untouched(): void
    {
        $cp = new FakeCandidateProvider([
            '22:0' => [$this->cand(22, 4, 'courier', 80, 5)],
            '88:0' => [],
        ]);
        $service = new DeliveryOptimizerService($cp, new FakeQuoteClient(), new FakeConfig());
        $state = $service->solve([$this->item(22)], $this->loc())['state'];

        $service->incrementalAdd($state, $this->item(88), $this->loc());

        $this->assertCount(1, $state->activeLegs());
        $this->assertArrayNotHasKey('88:0', $state->assignment);
    }
}
