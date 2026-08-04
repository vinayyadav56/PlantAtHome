<?php

namespace Marvel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Marvel\Services\AvailabilityService;

/**
 * Rebuild the city-availability projection for one vendor's whole catalogue.
 *
 * recomputeForShop loops recomputeForProduct over EVERY product the vendor
 * supplies — hundreds of products after a price-sheet import — and used to run
 * inline in the request. This moves the heavy path onto the queue; the cheap
 * single-product recompute stays inline everywhere (instant admin feedback).
 *
 * ShouldBeUnique dedupes by shop: an import that fires five recomputes queues
 * ONE job. uniqueFor caps the lock at 10 minutes so a dead worker can't wedge
 * a shop out of recomputation for long.
 *
 * Seconds of projection staleness are fine — the projection is a cache, and
 * the hard business rules (held vendors, effective dates, stock floors) are
 * enforced at query time on the live paths regardless.
 */
class RecomputeShopAvailabilityJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;
    public int $tries = 2;
    public int $timeout = 570;

    public function __construct(public int $shopId)
    {
        $this->onQueue('availability');
    }

    public function uniqueId(): string
    {
        return (string) $this->shopId;
    }

    public function handle(AvailabilityService $availability): void
    {
        $availability->recomputeForShop($this->shopId);
    }
}
