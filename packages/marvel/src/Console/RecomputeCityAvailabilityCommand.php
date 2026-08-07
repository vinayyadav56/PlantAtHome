<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Marvel\Database\Models\VendorProductPrice;
use Marvel\Services\AvailabilityService;

/**
 * Rebuild the product_city_availability projection from current vendor inventory ×
 * service areas. Run once to backfill existing inventory, and on a schedule to flush
 * lapsed effective windows (the projection is otherwise only refreshed on import /
 * service-area change). `--product=` recomputes a single master product.
 */
class RecomputeCityAvailabilityCommand extends Command
{
    protected $signature = 'marvel:recompute-city-availability {--product= : Recompute a single product id}';

    protected $description = 'Rebuild product_city_availability from vendor inventory + service areas';

    public function handle(): int
    {
        // Long-running contexts (queue worker via Artisan::queue, scheduler)
        // reuse MarginResolver's static margin map across runs — flush so a
        // margin edit made since the last run is actually applied.
        \Marvel\Services\MarginResolver::flush();

        $svc = new AvailabilityService();

        if ($single = $this->option('product')) {
            $svc->recomputeForProduct((int) $single);
            AvailabilityService::bustCatalogCache();
            $this->info("Recomputed city availability for product {$single}.");
            return self::SUCCESS;
        }

        // Union of products WITH vendor inventory and products still PRESENT in the
        // projection: a product whose vendor inventory was removed entirely (vendor
        // deleted / wiped) leaves ORPHANED projection rows that would otherwise never
        // be flushed — recomputing such a product deletes its stale rows.
        $pids = VendorProductPrice::query()->distinct()->pluck('product_id')
            ->merge(\Marvel\Database\Models\ProductCityAvailability::query()->distinct()->pluck('product_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $this->info('Recomputing city availability for ' . $pids->count() . ' products (inventory + projection)…');
        $bar = $this->output->createProgressBar($pids->count());
        foreach ($pids as $pid) {
            $svc->recomputeForProduct((int) $pid);
            $bar->advance();
        }
        $bar->finish();
        AvailabilityService::bustCatalogCache();
        $this->newLine();
        $this->info('Done.');
        return self::SUCCESS;
    }
}
