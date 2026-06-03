<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeValue;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\Type;

/**
 * Gives the catalog real prices. Plants become Pickbazar "variable" products
 * with a Size attribute (Small/Medium/Large), each size with its own price +
 * stock; the storefront then shows "from ₹X" and a size selector on the PDP.
 * The few demo tools/farmbox products get a flat price so nothing reads ₹0.
 *
 * Prices are DERIVED deterministically from the source data (category /
 * growth_rate / height_range) — stable across re-seeds. Existing
 * variation_options are preserved (admin per-size edits survive). Idempotent.
 *
 *   php artisan plantathome:apply-size-pricing
 */
class ApplySizePricingCommand extends Command
{
    protected $signature = 'plantathome:apply-size-pricing {--limit=0 : Only convert N plants (0 = all)}';

    protected $description = 'Convert plants to variable products with Small/Medium/Large size pricing; flat-price other demo products.';

    private const SIZES = ['Small', 'Medium', 'Large'];

    /** Larger pot/plant → higher price. Small = derived base. */
    private const SIZE_MULT = ['Small' => 1.0, 'Medium' => 1.7, 'Large' => 2.6];

    public function handle(): int
    {
        $plantsType = Type::where('slug', 'plants')->where('language', 'en')->first();
        if (!$plantsType) {
            $this->error('Plants type not found.');
            return self::FAILURE;
        }
        $shopId = Shop::where('slug', 'plantathome')->value('id');

        // 1. Ensure the Size attribute + Small/Medium/Large values (once).
        $attr = Attribute::firstOrCreate(
            ['slug' => 'size', 'language' => 'en', 'shop_id' => $shopId],
            ['name' => 'Size']
        );
        $valueIds = [];
        foreach (self::SIZES as $size) {
            $val = AttributeValue::firstOrCreate(
                ['attribute_id' => $attr->id, 'value' => $size, 'language' => 'en'],
                ['slug' => Str::slug($size), 'meta' => null]
            );
            $valueIds[$size] = $val->id;
        }
        $allValueIds = array_values($valueIds);

        // 2. Load pricing signals from the source data (slug → meta).
        $path = __DIR__ . '/../../data/plants.json';
        $plants = is_file($path) ? json_decode(file_get_contents($path), true) : [];
        $meta = [];
        foreach ($plants as $p) {
            $slug = $p['slug'] ?? Str::slug($p['name'] ?? '');
            if ($slug) {
                $meta[$slug] = [
                    'category'     => $p['category'] ?? '',
                    'growth_rate'  => $p['growth_rate'] ?? '',
                    'height_range' => $p['height_range'] ?? '',
                ];
            }
        }

        // 3. Convert each plant → variable with size variations.
        $limit = (int) $this->option('limit');
        $converted = 0;
        $query = Product::where('type_id', $plantsType->id)->where('language', 'en')->orderBy('id');
        $query->chunkById(200, function ($products) use (&$converted, $meta, $allValueIds, $limit) {
            DB::transaction(function () use ($products, &$converted, $meta, $allValueIds, $limit) {
                foreach ($products as $product) {
                    if ($limit > 0 && $converted >= $limit) {
                        return;
                    }
                    $this->applyPlant($product, $meta[$product->slug] ?? [], $allValueIds);
                    $converted++;
                }
            });
            return !($limit > 0 && $converted >= $limit);
        });

        // 4. Flat-price any remaining ₹0 non-plant products (tools/farmbox demo).
        $flat = 0;
        Product::where('type_id', '!=', $plantsType->id)->where('language', 'en')
            ->where(function ($q) {
                $q->whereNull('price')->orWhere('price', 0);
            })
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$flat) {
                foreach ($products as $product) {
                    $price = $this->roundTo9(199 + ($this->crc((string) $product->slug) % 600));
                    $product->forceFill([
                        'product_type' => 'simple',
                        'price'        => $price,
                        'sale_price'   => 0,
                        'min_price'    => $price,
                        'max_price'    => $price,
                        'quantity'     => 25,
                        'in_stock'     => true,
                    ])->save();
                    $flat++;
                }
            });

        // 5. Bust the product cache so the storefront shows prices immediately.
        Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);

        $this->info("Size-priced {$converted} plants (Small/Medium/Large); flat-priced {$flat} other products.");
        return self::SUCCESS;
    }

    /** Convert one plant to a variable product with 3 size variations. */
    private function applyPlant(Product $product, array $meta, array $allValueIds): void
    {
        $slug = (string) $product->slug;
        $base = $this->basePrice($slug, $meta); // the Small price

        foreach (self::SIZES as $size) {
            // Preserve any existing row (admin edits survive re-deploys).
            if ($product->variation_options()->where('title', $size)->exists()) {
                continue;
            }
            $price = $this->roundTo9($base * self::SIZE_MULT[$size]);
            $product->variation_options()->create([
                'title'      => $size,
                'price'      => $price,
                'sale_price' => $this->saleFor($slug, $price),
                'quantity'   => 8 + ($this->crc($slug . $size) % 33),
                'sku'        => $slug . '-' . Str::slug($size),
                'is_disable' => false,
                'language'   => 'en',
                'options'    => [['name' => 'Size', 'value' => $size]],
            ]);
        }

        // Link the Size attribute values (drives the PDP selector).
        $product->variations()->sync($allValueIds);

        // Recompute the summary from the actual rows (respects admin edits).
        $rows = $product->variation_options()->get();
        $product->forceFill([
            'product_type' => 'variable',
            'price'        => null,
            'sale_price'   => null,
            'sku'          => null,
            'min_price'    => (float) $rows->min(fn ($v) => (float) ($v->sale_price ?: $v->price)),
            'max_price'    => (float) $rows->max(fn ($v) => (float) $v->price),
            'quantity'     => (int) $rows->sum('quantity'),
            'in_stock'     => $rows->sum('quantity') > 0,
        ])->save();
    }

    /** Deterministic Small-size price from category + growth rate + height. */
    private function basePrice(string $slug, array $meta): float
    {
        $cat = strtolower((string) ($meta['category'] ?? ''));
        $base = 249;
        if (preg_match('/succulent|cact|air plant/', $cat))      $base = 149;
        elseif (preg_match('/herb/', $cat))                      $base = 99;
        elseif (preg_match('/fruit|tree/', $cat))                $base = 599;
        elseif (preg_match('/fern|palm/', $cat))                 $base = 399;
        elseif (preg_match('/flower|climb|vine|creeper|shrub/', $cat)) $base = 349;
        elseif (preg_match('/foliage|leaf/', $cat))              $base = 299;

        $g = strtolower((string) ($meta['growth_rate'] ?? ''));
        $mult = 1.0;
        if (str_contains($g, 'very slow'))      $mult = 1.5;
        elseif (str_contains($g, 'slow'))       $mult = 1.25;
        elseif (str_contains($g, 'very fast'))  $mult = 0.85;
        elseif (str_contains($g, 'fast'))       $mult = 0.9;

        $height = $this->maxMetres((string) ($meta['height_range'] ?? ''));
        $heightAdj = min(200.0, max(0.0, ($height - 1) * 20)); // +₹20/m over 1m, capped ₹200

        $jitter = 1 + ((($this->crc($slug) % 25) - 12) / 100.0); // ±12%, deterministic
        return max(49.0, ($base * $mult + $heightAdj) * $jitter);
    }

    /** Largest height in the range, in metres (e.g. "30 cm - 1.5 m" → 1.5). */
    private function maxMetres(string $range): float
    {
        if (preg_match_all('/([\d.]+)\s*(cm|m)/i', $range, $m, PREG_SET_ORDER)) {
            $last = end($m);
            $v = (float) $last[1];
            return strtolower($last[2]) === 'cm' ? $v / 100 : $v;
        }
        return 1.0;
    }

    /** ~35% of products get a deterministic 12–18% discount; rest none. */
    private function saleFor(string $slug, int $price): ?int
    {
        if (($this->crc($slug . 'sale') % 100) >= 35) {
            return null;
        }
        $pct = 12 + ($this->crc($slug . 'pct') % 7);
        return $this->roundTo9($price * (1 - $pct / 100));
    }

    /** Round to a retail-looking …9 ending. */
    private function roundTo9(float $p): int
    {
        return (int) max(49, ((int) round($p / 10) * 10) - 1);
    }

    private function crc(string $s): int
    {
        return crc32($s) & 0x7fffffff;
    }
}
