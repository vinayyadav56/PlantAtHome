<?php

namespace Marvel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Marvel\Services\AvailabilityService;
use Marvel\Services\MarginResolver;

/**
 * Rebuild the city-availability projection for ONE product.
 *
 * Fired on order stock movements (reserve / release / commit) so the
 * projection's stock — and the "in stock" storefront gate — tracks orders
 * instead of waiting for the 03:30 full rebuild. Queued (not inline): stock
 * moves happen inside checkout/assignment hot paths.
 *
 * ShouldBeUnique per product with a short window: a burst of movements on one
 * product queues one recompute. Seconds of staleness are fine — the projection
 * is a cache; oversell protection is the atomic reserveStock guard, not this.
 */
class RecomputeProductAvailabilityJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 120;
    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public int $productId)
    {
        $this->onQueue('availability');
    }

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public function handle(AvailabilityService $availability): void
    {
        // Long-lived worker: the margin matrix is a process static (see
        // RecomputeShopAvailabilityJob for the full story).
        MarginResolver::flush();
        $availability->recomputeForProduct($this->productId);
        $availability->bustCatalogCache();
    }
}
