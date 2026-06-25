<?php

namespace Tests\Unit\DeliveryOptimizer\Fakes;

use Marvel\Services\DeliveryOptimizer\Dto\Candidate;
use Marvel\Services\DeliveryOptimizer\Dto\CartItem;
use Marvel\Services\DeliveryOptimizer\Support\Rail;

/** Tiny builders so scenario tests read declaratively. */
trait MakesOptimizerFixtures
{
    protected function item(int $productId, int $qty = 1, int $weightG = 500): CartItem
    {
        return new CartItem($productId, null, $qty, $weightG);
    }

    /**
     * One candidate. $rail = 'local' (INSTANT) or 'courier' (COURIER).
     */
    protected function cand(
        int $productId,
        int $vendorId,
        string $mode,
        float $baseCost,
        int $eta,
        ?float $price = 100.0,
        float $perKg = 0.0,
        float $score = 1.0
    ): Candidate {
        return new Candidate(
            $productId . ':0',
            $vendorId,
            Rail::fromFulfillmentMode($mode),
            $mode,
            $eta,
            $eta,
            $price,
            $baseCost,
            $perKg,
            $score,
            true,
        );
    }
}
