<?php

namespace Tests\Feature;

use Marvel\Database\Repositories\CheckoutRepository;
use Marvel\Services\DeliveryOptimizer\Contracts\OptimizerConfigInterface;
use Tests\TestCase;

/**
 * The Delivery Optimizer FEE CUTOVER seam: CheckoutRepository::optimizerFlatFee(). When the
 * optimizer flag is ON the customer is charged its consolidated flat fee (free above the shared
 * freeShipping threshold); when OFF (or on any failure) it returns null and both charge sites
 * (verify + storeOrder) keep the legacy per-product path byte-identical. The fee is a pure
 * function of amount + settings — no quotes — so verify/storeOrder can never drift.
 *
 * The verify()/storeOrder() wiring itself is a two-line null-guard asserted by code review
 * (same precedent as CheckoutCoverageGateTest); these tests pin the seam's behavior.
 */
final class DeliveryFeeCutoverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate from the ambient DB: with no settings table, OptimizerConfig's settings
        // overlay fail-safes to [] and every value comes deterministically from config().
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
            ],
        ]);
        \Illuminate\Support\Facades\DB::purge('sqlite');
    }

    public function test_flag_off_returns_null_so_legacy_charge_is_used(): void
    {
        config()->set('deliveryoptimizer.enabled', false);

        $fee = (new CheckoutRepository())->optimizerFlatFee(500.0);

        $this->assertNull($fee);
    }

    public function test_flag_on_charges_the_consolidated_flat_fee(): void
    {
        config()->set('deliveryoptimizer.enabled', true);
        config()->set('deliveryoptimizer.flat_fee', 49.0);
        // Keep the cart below the free-delivery threshold so the flat fee applies.
        config()->set('deliveryoptimizer.free_delivery_enabled', true);
        config()->set('deliveryoptimizer.free_delivery_threshold', 999.0);

        $fee = (new CheckoutRepository())->optimizerFlatFee(500.0);

        $this->assertSame(49.0, $fee);
    }

    public function test_flag_on_is_free_at_or_above_the_threshold(): void
    {
        config()->set('deliveryoptimizer.enabled', true);
        config()->set('deliveryoptimizer.flat_fee', 49.0);
        config()->set('deliveryoptimizer.free_delivery_enabled', true);
        config()->set('deliveryoptimizer.free_delivery_threshold', 999.0);

        $repo = new CheckoutRepository();

        $this->assertSame(0.0, $repo->optimizerFlatFee(999.0));
        $this->assertSame(0.0, $repo->optimizerFlatFee(2500.0));
        $this->assertSame(49.0, $repo->optimizerFlatFee(998.99));
    }

    public function test_any_failure_fails_open_to_legacy(): void
    {
        // A config gate that explodes must NOT break checkout — the seam returns null and
        // the legacy per-product charge is used.
        $this->app->bind(OptimizerConfigInterface::class, function () {
            return new class implements OptimizerConfigInterface {
                public function enabled(): bool { throw new \RuntimeException('boom'); }
                public function topK(): int { return 5; }
                public function timeBudgetMs(): int { return 50; }
                public function instantTtl(): int { return 60; }
                public function courierTtl(): int { return 600; }
                public function slaPenaltyPerDay(): float { return 0.0; }
                public function targetSlaDays(): int { return 3; }
                public function baseFlatFee(): float { return 49.0; }
                public function freeDeliveryEnabled(): bool { return true; }
                public function freeDeliveryThreshold(): float { return 999.0; }
                public function firmQuotesAtBrowse(): bool { return false; }
                public function firmTimeoutMs(): int { return 800; }
                public function defaultWeightG(): int { return 500; }
                public function fullReoptEveryNEvents(): int { return 5; }
                public function marginalReoptThreshold(): float { return 0.2; }
            };
        });

        $fee = (new CheckoutRepository())->optimizerFlatFee(500.0);

        $this->assertNull($fee);
    }
}
