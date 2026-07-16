<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeValue;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\Type;

/**
 * Tier 2 — safe for all environments (updateOrCreate on slug; idempotent).
 *
 * Seeds the "Pots & Planters" vertical so the storefront + app "buy with pot /
 * without pot" picker has pots to offer:
 *   - the `pots-planters` TYPE (mirrors PlantAtHomeTypeSeeder)
 *   - material CATEGORIES (Ceramic / Wooden / Plastic Pots) under that type —
 *     these drive the picker's material chips
 *   - the pot PRODUCTS from packages/marvel/data/pots.json, each a VARIABLE
 *     product with Small / Medium / Large size variations (the SAME Size
 *     attribute plants use) so a pot can be size-matched to the plant.
 *
 * IDEMPOTENT: pots upsert by slug; size variation rows are created once and then
 * PRESERVED on re-run (admin price/stock edits survive), exactly like
 * ApplySizePricingCommand. No TRUNCATE — safe to run on production.
 *
 * Run:  php artisan db:seed --class="Marvel\\Database\\Seeders\\PlantAtHomePotSeeder" --force
 */
class PlantAtHomePotSeeder extends Seeder
{
    private const SIZES = ['Small', 'Medium', 'Large'];

    private function dataPath(): string
    {
        return base_path('packages/marvel/data/pots.json');
    }

    public function run(): void
    {
        $path = $this->dataPath();
        if (!file_exists($path)) {
            $this->command->error("[Pots] pots.json not found at {$path}");
            return;
        }
        $pots = json_decode(file_get_contents($path), true);
        if (empty($pots)) {
            $this->command->error('[Pots] pots.json is empty or malformed.');
            return;
        }

        // 1. Ensure the pots-planters TYPE (same shape as PlantAtHomeTypeSeeder).
        $type = Type::updateOrCreate(
            ['slug' => 'pots-planters', 'language' => 'en'],
            [
                'name'                => 'Pots & Planters',
                'icon'                => 'Basket',
                'settings'            => ['isHome' => false, 'layoutType' => 'classic', 'productCard' => 'argon'],
                'promotional_sliders' => null,
            ]
        );

        // 2. Master shop — products need a shop to show + be checkout-able.
        $shopId = Shop::where('slug', 'plantathome')->value('id') ?: Shop::orderBy('id')->value('id');
        if (!$shopId) {
            $this->command->warn('[Pots] No shop found — run the shop/type seeders first.');
            return;
        }

        // 3. Size attribute + Small/Medium/Large values (shared with plants —
        //    firstOrCreate matches the existing rows, never duplicates them).
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

        // 4. Material CATEGORIES under the pots-planters type (drive the chips).
        $catIds = [];
        $imgId = 9500;
        foreach ($pots as $p) {
            $mat = $p['material'] ?? 'Pots';
            if (isset($catIds[$mat])) {
                continue;
            }
            $cat = Category::updateOrCreate(
                ['slug' => Str::slug($mat), 'language' => 'en'],
                [
                    'name'     => $mat,
                    'icon'     => 'Pot',
                    'details'  => "{$mat} sized to fit every plant",
                    'type_id'  => $type->id,
                    'language' => 'en',
                    'parent'   => null,
                    'image'    => $this->imageJson(++$imgId, $p['image'] ?? []),
                ]
            );
            $catIds[$mat] = $cat->id;
        }

        // 5. Pot PRODUCTS — variable, Small/Medium/Large.
        $created = 0;
        $updated = 0;
        $errors  = 0;
        foreach ($pots as $i => $p) {
            $name = trim($p['name'] ?? '');
            $slug = trim($p['slug'] ?? Str::slug($name));
            if (!$name || !$slug) {
                $errors++;
                continue;
            }
            try {
                $product = Product::updateOrCreate(
                    ['slug' => $slug, 'language' => 'en'],
                    [
                        'name'         => $name,
                        'description'  => $p['description'] ?? null,
                        'type_id'      => $type->id,
                        'shop_id'      => $shopId,
                        'language'     => 'en',
                        'status'       => 'publish',
                        'visibility'   => 'visibility_public',
                        'product_type' => 'variable',
                        'is_taxable'   => false,
                        'unit'         => $p['unit'] ?? '1 Pot',
                        'image'        => $this->imageJson(9600 + (int) $i, $p['image'] ?? []),
                    ]
                );
                $product->wasRecentlyCreated ? $created++ : $updated++;

                // Size variation rows — create once, then preserve (admin edits survive).
                foreach (($p['sizes'] ?? []) as $s) {
                    $size = $s['size'] ?? null;
                    if (!$size) {
                        continue;
                    }
                    if ($product->variation_options()->where('title', $size)->exists()) {
                        continue;
                    }
                    $product->variation_options()->create([
                        'title'      => $size,
                        'price'      => (float) ($s['price'] ?? 0),
                        'sale_price' => null,
                        'quantity'   => (int) ($s['quantity'] ?? 50),
                        'sku'        => $s['sku'] ?? ($slug . '-' . Str::slug($size)),
                        'is_disable' => false,
                        'language'   => 'en',
                        'options'    => [['name' => 'Size', 'value' => $size]],
                    ]);
                }

                // Link the Size attribute values (drives the PDP size selector).
                $product->variations()->sync($allValueIds);

                // Recompute the summary from the actual size rows.
                $rows = $product->variation_options()->get();
                $product->forceFill([
                    'price'      => null,
                    'sale_price' => null,
                    'sku'        => null,
                    'min_price'  => (float) $rows->min(fn ($v) => (float) $v->price),
                    'max_price'  => (float) $rows->max(fn ($v) => (float) $v->price),
                    'quantity'   => (int) $rows->sum('quantity'),
                    'in_stock'   => $rows->sum('quantity') > 0,
                ])->save();

                // Material category link.
                $mat = $p['material'] ?? 'Pots';
                if (isset($catIds[$mat])) {
                    $product->categories()->sync([$catIds[$mat]]);
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->command->warn("[Pots] Error on {$name}: " . $e->getMessage());
            }
        }

        $this->command->info(
            "[Pots] Pots & Planters seeded — created: {$created}, updated: {$updated}, errors: {$errors}, categories: " . count($catIds)
        );
    }

    /** Pickbazar image json {id, original, thumbnail}. */
    private function imageJson(int $id, array $img): ?array
    {
        $orig = $img['original'] ?? null;
        if (!$orig) {
            return null;
        }
        return ['id' => $id, 'original' => $orig, 'thumbnail' => $img['thumbnail'] ?? $orig];
    }
}
