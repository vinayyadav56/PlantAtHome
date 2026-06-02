<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Marvel\Database\Models\Product;

/**
 * Rebuilds the legacy image/gallery columns from the product_images library
 * for every plant that has library rows. Use this to repair plants whose
 * photos were downloaded (library populated) but whose image/gallery columns
 * were never synced — so the photos show up on the product edit page.
 *
 *   php artisan plantathome:sync-image-columns
 *
 * Idempotent and safe to re-run.
 */
class SyncImageColumnsCommand extends Command
{
    protected $signature = 'plantathome:sync-image-columns
        {--only-empty : only resync plants whose image/gallery columns are currently empty}';

    protected $description = 'Rebuild image/gallery columns from the product_images library (repairs unsynced plants).';

    public function handle(): int
    {
        $query = Product::has('images');
        if ($this->option('only-empty')) {
            $query->whereNull('image');
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('Nothing to sync — no plants with library images match.');
            return self::SUCCESS;
        }

        $this->info("Syncing image/gallery columns for {$total} plant(s)…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $synced = 0;
        $query->orderBy('id')->chunkById(200, function ($products) use (&$synced, $bar) {
            foreach ($products as $product) {
                $product->syncImageColumns();
                $synced++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Synced {$synced} plant(s).");

        return self::SUCCESS;
    }
}
