<?php

namespace Tests\Feature;

use Marvel\Services\DeliveryOptimizer\Contracts\CandidateProviderInterface;
use Marvel\Services\DeliveryOptimizer\Contracts\OptimizerConfigInterface;
use Marvel\Services\DeliveryOptimizer\Contracts\ShippingQuoteClientInterface;
use Marvel\Services\DeliveryOptimizer\DeliveryOptimizerService;
use Marvel\Services\DeliveryOptimizer\Dto\UserLocation;
use Tests\TestCase;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeCandidateProvider;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeQuoteClient;
use Tests\Unit\DeliveryOptimizer\Fakes\MakesOptimizerFixtures;

/**
 * Boots Laravel to prove the DeliveryOptimizerServiceProvider wiring + config/deliveryoptimizer.php
 * are correct: the container resolves the optimizer (interfaces bound to defaults), reads the
 * config, and runs the locked scenario end-to-end. Candidate/quote sources are overridden with
 * fakes so no DB/shipping-service is required.
 */
final class OptimizerWiringTest extends TestCase
{
    use MakesOptimizerFixtures;

    public function test_container_resolves_optimizer_and_runs_locked_scenario(): void
    {
        config()->set('deliveryoptimizer.enabled', true);

        $cp = new FakeCandidateProvider([
            '11:0' => [$this->cand(11, 1, 'local', 50, 2)],
            '12:0' => [$this->cand(12, 1, 'local', 50, 2)],
            '13:0' => [$this->cand(13, 1, 'local', 50, 2)],
            '14:0' => [$this->cand(14, 1, 'local', 50, 2)],
            '15:0' => [$this->cand(15, 2, 'local', 50, 2)],
            '21:0' => [$this->cand(21, 3, 'courier', 80, 5)],
            '22:0' => [$this->cand(22, 4, 'courier', 80, 5)],
            '23:0' => [$this->cand(23, 4, 'courier', 80, 5)],
        ]);
        $this->app->bind(CandidateProviderInterface::class, fn () => $cp);
        $this->app->bind(ShippingQuoteClientInterface::class, fn () => new FakeQuoteClient());

        $service = $this->app->make(DeliveryOptimizerService::class);
        $result = $service->optimizeCart([
            $this->item(11), $this->item(12), $this->item(13), $this->item(14),
            $this->item(15), $this->item(21), $this->item(22), $this->item(23),
        ], new UserLocation('Gurugram', '122001'));

        $this->assertCount(4, $result->shipments);
        $this->assertEqualsWithDelta(260.0, $result->internalTrueCost, 0.001);

        // Customer-safe shape: present keys, and NO internal cost / vendor economics leaked.
        $arr = $result->toArray();
        foreach (['shipments', 'customer_flat_fee', 'subtotal', 'delivery_estimates', 'upsell_hint', 'quote_source', 'unfulfillable_items'] as $key) {
            $this->assertArrayHasKey($key, $arr);
        }
        $this->assertArrayNotHasKey('internal_true_cost', $arr);
        foreach ($arr['shipments'] as $s) {
            $this->assertArrayNotHasKey('vendor_id', $s);
            $this->assertArrayNotHasKey('true_cost', $s);
        }

        // Internal shape (admin/logging only) DOES carry the economics.
        $internal = $result->toInternalArray();
        $this->assertArrayHasKey('internal_true_cost', $internal);
        $this->assertArrayHasKey('vendor_id', $internal['shipments'][0]);
    }

    public function test_optimizer_config_reads_from_config_file(): void
    {
        config()->set('deliveryoptimizer.top_k', 7);
        config()->set('deliveryoptimizer.time_budget_ms', 42);

        $cfg = $this->app->make(OptimizerConfigInterface::class);

        $this->assertSame(7, $cfg->topK());
        $this->assertSame(42, $cfg->timeBudgetMs());
    }
}
