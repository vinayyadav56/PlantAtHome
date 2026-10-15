<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Marvel\Database\Models\Product;
use Marvel\Services\SkuGenerator;

/**
 * Backfills unique SKUs onto products and variations that don't have one, including the
 * variations of products that already have their own SKU.
 * Idempotent — only fills empty SKUs unless --force is passed.
 *
 *   php artisan plantathome:generate-skus
 *   php artisan plantathome:generate-skus --force   (regenerate all)
 */
class GenerateSkusCommand extends Command
{
    protected $signature = 'plantathome:generate-skus {--force : regenerate SKUs even if already set}';

    protected $description = 'Generate unique {VERTICAL}-{CATEGORY}-{SEQ} SKUs for products + variations.';

    public function handle(SkuGenerator $gen): int
    {
        $force = (bool) $this->option('force');

        $query = Product::query()->with(['type', 'categories']);
        if (!$force) {
            $blank = fn ($q) => $q->whereNull('sku')->orWhere('sku', '');
            $query->where(fn ($w) => $w->where($blank)->orWhereHas('variation_options', fn ($v) => $v->where($blank)));
        }

        $products = 0;
        $variations = 0;

        $query->chunkById(200, function ($chunk) use ($gen, $force, &$products, &$variations) {
            foreach ($chunk as $product) {
                if ($force || empty($product->sku)) {
                    $product->sku = $gen->forProduct($product);
                    $product->saveQuietly();
                    $products++;
                }

                $variations += $gen->fillVariantSkus($product, $force);
            }
        });

        $this->info("SKUs generated — products: {$products}, variations: {$variations}.");

        return self::SUCCESS;
    }
}
