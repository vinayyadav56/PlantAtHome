<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Type;

/**
 * Tier 2 — safe for all environments.
 *
 * Seeds the Tools vertical from packages/marvel/data/tools.json into the
 * products table + category_product pivot. Unlike plants, tools are simple,
 * fixed-price products (price/sale_price/quantity set here), with no
 * plant_attributes row.
 *
 * CREATE-ONLY: a tool is created listable (status publish + the Master Catalog
 * flags) and never re-dressed afterwards. This runs on every staging boot and in
 * prod-data-op modes; re-applying name/copy/price/stock/type here used to undo
 * every admin edit (a price change, a bundle conversion) on the next boot.
 * Images are left null and sourced separately (admin upload or an image pass) —
 * the storefront card shows an elegant branded fallback.
 *
 * Run:  php artisan db:seed --class="Marvel\\Database\\Seeders\\PlantAtHomeToolsSeeder" --force
 */
class PlantAtHomeToolsSeeder extends Seeder
{
    private function dataPath(): string
    {
        return base_path('packages/marvel/data/tools.json');
    }

    public function run(): void
    {
        $path = $this->dataPath();
        if (!file_exists($path)) {
            $this->command->error("[Tools] tools.json not found at {$path}");
            return;
        }

        $tools = json_decode(file_get_contents($path), true);
        if (empty($tools)) {
            $this->command->error('[Tools] tools.json is empty or malformed.');
            return;
        }

        $type = Type::where('slug', 'tools')->where('language', 'en')->first();
        if (!$type) {
            $this->command->warn('[Tools] Tools type not found — run PlantAtHomeTypeSeeder first.');
            return;
        }

        // category slug → id index for the tools type
        $categoryIndex = Category::where('language', 'en')
            ->where('type_id', $type->id)
            ->pluck('id', 'slug');

        $created = 0;
        $updated = 0;
        $errors  = 0;

        foreach ($tools as $t) {
            $name = trim($t['name'] ?? '');
            $slug = trim($t['slug'] ?? Str::slug($name));
            if (!$name || !$slug) {
                $errors++;
                continue;
            }

            try {
                $price    = (float) ($t['price'] ?? 0);
                $sale     = isset($t['sale_price']) ? (float) $t['sale_price'] : null;
                $quantity = (int) ($t['quantity'] ?? 0);

                $product = Product::firstOrNew(['slug' => $slug, 'language' => 'en']);
                if (!$product->exists) {
                    $product->fill([
                        'name'                 => $name,
                        'description'          => $t['description'] ?? null,
                        'status'               => 'publish',
                        'visibility'           => 'visibility_public',
                        'product_type'         => 'simple',
                        'in_stock'             => $quantity > 0,
                        'is_taxable'           => false,
                        'unit'                 => $t['unit'] ?? '1 Piece',
                        'price'                => $price,
                        'sale_price'           => $sale,
                        'min_price'            => $price,
                        'max_price'            => $price,
                        'quantity'             => $quantity,
                        // Master Catalog membership: listable from the start (the gate
                        // defaults to hidden and nothing else would ever switch it on).
                        'is_available_product' => true,
                        'listing_enabled'      => true,
                        'available_at'         => now(),
                        // images sourced separately; card shows branded fallback meanwhile
                    ]);
                }
                $product->fill(['type_id' => $type->id])->save();

                $product->wasRecentlyCreated ? $created++ : $updated++;

                // Category only for a tool that has none (new, or seeded before its
                // category existed) — an admin re-categorisation is never overwritten.
                $catSlug = isset($t['category']) ? Str::slug($t['category']) : null;
                if ($catSlug && isset($categoryIndex[$catSlug])) {
                    if (!$product->categories()->exists()) {
                        $product->categories()->sync([$categoryIndex[$catSlug]]);
                    }
                } elseif ($catSlug) {
                    $this->command->warn("[Tools] Category not found: {$catSlug} for tool: {$name}");
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->command->warn("[Tools] Error on {$name}: " . $e->getMessage());
            }
        }

        $this->command->info(
            "[Tools] PlantAtHome tools seeded — created: {$created}, kept: {$updated}, errors: {$errors} (total: " . count($tools) . ")"
        );
    }
}
