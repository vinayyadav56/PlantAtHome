<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;

/**
 * Restores the curated catalogue's visibility.
 *
 * The 2026-08-30 catalog flips set is_available_product=1 on the curated
 * plants (per the owner's "auto available for master catalogue" instruction)
 * but never enabled their listings — and applyCatalogGate requires BOTH, so
 * the storefront listing has shown ~0 products since. This enables listings
 * for exactly the curated, priced, public, published set, with a backup
 * table for one-statement rollback.
 */
class EnableCuratedListingsCommand extends Command
{
    protected $signature = 'plantathome:enable-curated-listings {--dry-run : Report without writing}';

    protected $description = 'Enable storefront listings for available, priced, published products';

    public function handle(): int
    {
        $backupTable = 'pah_listing_flip_backup_' . now()->format('Ymd');

        $eligible = Product::where('is_available_product', true)
            ->where('status', 'publish')
            ->where('visibility', 'visibility_public')
            ->where('min_price', '>', 0)
            ->where('listing_enabled', false);

        $count = (clone $eligible)->count();
        $this->info(($this->option('dry-run') ? '[dry-run] ' : '') . "eligible for listing: {$count}");
        if ($this->option('dry-run') || $count === 0) {
            return self::SUCCESS;
        }

        if (! DB::getSchemaBuilder()->hasTable($backupTable)) {
            DB::statement("CREATE TABLE {$backupTable} AS
                SELECT id, listing_enabled, NOW() AS backed_up_at FROM products WHERE is_available_product = 1");
            $this->line("backup: {$backupTable} (restore: UPDATE products p JOIN {$backupTable} b ON b.id = p.id SET p.listing_enabled = b.listing_enabled)");
        }

        $flipped = $eligible->update(['listing_enabled' => true]);
        $listed = Product::where('is_available_product', true)->where('listing_enabled', true)->count();
        $this->info("listings enabled: {$flipped}; now listed: {$listed}");

        return self::SUCCESS;
    }
}
