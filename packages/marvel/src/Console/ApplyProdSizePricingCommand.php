<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\AttributeValue;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Type;

/**
 * ONE-OFF, reversible production conversion of plant products from `simple`
 * to `variable` with a Size attribute (Small/Medium/Large).
 *
 * Production plants already carry a real min_price/price/max_price ladder, so
 * sizes use REAL prices where available and derive only the missing ends:
 *   Small  = min_price  (if min < price)  else round9(price * 0.7)
 *   Medium = price
 *   Large  = max_price  (if max > price)  else round9(price * 1.4)
 *
 * Safety: every plant's original pricing is snapshotted to pah_plant_price_backup
 * BEFORE mutation (once). --rollback restores it exactly. Supports --dry-run and
 * --limit for a staged rollout on the live store. Idempotent.
 *
 *   php artisan plantathome:size-price-prod --dry-run
 *   php artisan plantathome:size-price-prod --limit=10
 *   php artisan plantathome:size-price-prod
 *   php artisan plantathome:size-price-prod --rollback
 */
class ApplyProdSizePricingCommand extends Command
{
    protected $signature = 'plantathome:size-price-prod
        {--dry-run : Print the planned conversion without writing anything}
        {--limit=0 : Only convert N plants (0 = all)}
        {--group=all : all = every plant; ladder = only plants with a real min<price<max}
        {--rollback : Restore plants to their original simple pricing from the backup table}';

    protected $description = 'Reversibly convert production plants to variable Small/Medium/Large size pricing (real prices preserved).';

    /** @var array<int, string> cheapest first, read from the Size attribute */
    private array $sizes = [];

    public function handle(): int
    {
        $type = Type::where('slug', 'plants')->where('language', 'en')->first();
        if (!$type) {
            $this->error('Plants type not found.');
            return self::FAILURE;
        }

        // The Size attribute is the variant master, so the ladder is however many
        // sizes it defines -- not a literal three this command carries itself.
        $this->sizes = sizeNames();

        if ($this->option('rollback')) {
            return $this->rollback($type);
        }

        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $ladderOnly = $this->option('group') === 'ladder';

        // Ensure the Size attribute + values (skip writes on dry-run).
        $valueIds = $dry ? array_fill_keys($this->sizes, 0) : $this->ensureSizeAttribute();

        $ladder = 0;   // real min<price<max
        $derived = 0;  // flat → derived ends
        $converted = 0;
        $samples = [];

        Product::where('type_id', $type->id)->where('language', 'en')
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$ladder, &$derived, &$converted, &$samples, $dry, $limit, $ladderOnly, $valueIds, $type) {
                foreach ($products as $p) {
                    if ($limit > 0 && $converted >= $limit) {
                        return false;
                    }
                    // Already converted? skip (idempotent).
                    if ($p->product_type === 'variable' && $p->variation_options()->whereIn('title', $this->sizes)->exists()) {
                        continue;
                    }

                    $price = (float) $p->price;
                    if ($price <= 0) {
                        continue; // never price a ₹0 product blindly
                    }
                    $isLadder = ((float) $p->min_price > 0 && (float) $p->min_price < $price) && ((float) $p->max_price > $price);
                    if ($ladderOnly && !$isLadder) {
                        continue;
                    }

                    $small = $isLadder ? (float) $p->min_price : $this->round9($price * 0.7);
                    $large = ((float) $p->max_price > $price) ? (float) $p->max_price : $this->round9($price * 1.4);
                    $sizes = $this->ladderAcross($small, $price, $large);

                    $isLadder ? $ladder++ : $derived++;
                    $converted++;
                    if (count($samples) < 6) {
                        $samples[] = sprintf('%-26s %s  %d/%d/%d', Str::limit($p->name, 25), $isLadder ? 'real ' : 'deriv', $small, $price, $large);
                    }

                    if (!$dry) {
                        DB::transaction(fn () => $this->convert($p, $sizes, $valueIds));
                    }
                }
                return true;
            });

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Plants to size-price: {$converted}  (real-ladder {$ladder}, derived {$derived})");
        foreach ($samples as $s) {
            $this->line('   ' . $s);
        }
        if (!$dry) {
            $this->bustProductCache();
            $this->info('Done. Product cache busted.');
        } else {
            $this->warn('Dry-run only — nothing written. Re-run without --dry-run (or via the workflow mode) to apply.');
        }
        return self::SUCCESS;
    }

    /** Snapshot original pricing (once), then write the 3 size variations. */
    private function convert(Product $p, array $sizes, array $valueIds): void
    {
        $exists = DB::table('pah_plant_price_backup')->where('product_id', $p->id)->exists();
        if (!$exists) {
            DB::table('pah_plant_price_backup')->insert([
                'product_id'   => $p->id,
                'product_type' => $p->product_type,
                'price'        => $p->price,
                'sale_price'   => $p->sale_price,
                'min_price'    => $p->min_price,
                'max_price'    => $p->max_price,
                'quantity'     => $p->quantity,
                'sku'          => $p->sku,
                'backed_up_at' => now(),
            ]);
        }

        $qParts = $this->splitQty((int) $p->quantity);
        foreach ($this->sizes as $size) {
            if ($p->variation_options()->where('title', $size)->exists()) {
                continue;
            }
            $p->variation_options()->create([
                'title'      => $size,
                'price'      => (int) $sizes[$size],
                'sale_price' => null,
                'quantity'   => $qParts[$size],
                'sku'        => $p->slug . '-' . Str::slug($size),
                'is_disable' => false,
                'language'   => 'en',
                'options'    => [['name' => 'Size', 'value' => $size]],
            ]);
        }

        $p->variations()->sync(array_values($valueIds));

        $rows = $p->variation_options()->get();
        $p->forceFill([
            'product_type' => 'variable',
            'price'        => null,
            'sale_price'   => null,
            'sku'          => null,
            'min_price'    => (float) $rows->min(fn ($v) => (float) $v->price),
            'max_price'    => (float) $rows->max(fn ($v) => (float) $v->price),
            'quantity'     => (int) $rows->sum('quantity'),
            'in_stock'     => $rows->sum('quantity') > 0,
        ])->save();
    }

    /** Restore every backed-up plant to its original simple pricing. */
    private function rollback(Type $type): int
    {
        $backups = DB::table('pah_plant_price_backup')->get();
        if ($backups->isEmpty()) {
            $this->warn('No backup rows — nothing to roll back.');
            return self::SUCCESS;
        }
        // Every value ever filed under Size, not just the ones currently named:
        // a size renamed after conversion would otherwise stay attached forever.
        $sizeAttrValueIds = allSizeValueIds();

        $restored = 0;
        foreach ($backups as $b) {
            $p = Product::find($b->product_id);
            if (!$p) {
                continue;
            }
            DB::transaction(function () use ($p, $b, $sizeAttrValueIds, &$restored) {
                $p->variation_options()->whereIn('title', $this->sizes)->delete();
                if ($sizeAttrValueIds) {
                    $p->variations()->detach($sizeAttrValueIds);
                }
                $p->forceFill([
                    'product_type' => $b->product_type ?: 'simple',
                    'price'        => $b->price,
                    'sale_price'   => $b->sale_price,
                    'min_price'    => $b->min_price,
                    'max_price'    => $b->max_price,
                    'quantity'     => $b->quantity,
                    'sku'          => $b->sku,
                    'in_stock'     => (int) $b->quantity > 0,
                ])->save();
                $restored++;
            });
        }
        $this->bustProductCache();
        $this->info("Rolled back {$restored} plants to their original simple pricing.");
        return self::SUCCESS;
    }

    /**
     * Shared helper — this used to key firstOrCreate on a shop_id derived from
     * the first plant's shop, which minted a second "Size" attribute and doubled
     * the size chips on every affected product page.
     */
    private function ensureSizeAttribute(): array
    {
        return sizeValueIds($this->sizes);
    }

    /**
     * Spread the product's real price ladder across however many sizes there are.
     * With the usual three this returns exactly [min, price, max] -- the price the
     * product already carries stays on the middle size. With more, the interior
     * sizes interpolate along the same piecewise-linear curve, so adding a size
     * never re-prices the two ends.
     *
     * @return array<string, int>
     */
    private function ladderAcross(float $small, float $price, float $large): array
    {
        $n = count($this->sizes);
        if ($n === 1) {
            return [$this->sizes[0] => (int) round($price)];
        }

        $out = [];
        foreach ($this->sizes as $i => $name) {
            $t = $i / ($n - 1);
            $out[$name] = (int) round(
                $t <= 0.5
                    ? $small + ($price - $small) * ($t / 0.5)
                    : $price + ($large - $price) * (($t - 0.5) / 0.5)
            );
        }

        return $out;
    }

    /**
     * Split stock across sizes, preserving the total (each >= 1). Stock skews to
     * the middle of the ladder, which is what sells; the 35/40/25 weights are the
     * ones the catalog launched with and still apply to the usual three sizes.
     *
     * @return array<string, int>
     */
    private function splitQty(int $q): array
    {
        $n = count($this->sizes);
        if ($q <= $n) {
            return array_fill_keys($this->sizes, 1);
        }

        $weights = $n === 3 ? [0.35, 0.40, 0.25] : array_fill(0, $n, 1 / $n);

        $out = [];
        $used = 0;
        foreach ($this->sizes as $i => $name) {
            if ($i === $n - 1) {
                $out[$name] = max(1, $q - $used);
                break;
            }
            $out[$name] = max(1, (int) round($q * $weights[$i]));
            $used += $out[$name];
        }

        return $out;
    }

    private function round9(float $p): int
    {
        return (int) max(9, ((int) round($p / 10) * 10) - 1);
    }

    private function bustProductCache(): void
    {
        \Illuminate\Support\Facades\Cache::forever('products:ver', (int) \Illuminate\Support\Facades\Cache::get('products:ver', 1) + 1);
    }
}
