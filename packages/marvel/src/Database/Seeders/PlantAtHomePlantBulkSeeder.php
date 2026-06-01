<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\PlantAttribute;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Type;

/**
 * Tier 2 — safe for all environments (updateOrCreate on slug).
 *
 * Seeds 1,530 real plants from packages/marvel/data/plants.json into:
 *   - products table (core e-commerce fields)
 *   - plant_attributes table (botanical details, one-to-one with products)
 *   - category_product pivot (many-to-many linkage)
 *
 * This seeder is IDEMPOTENT: re-running updates existing plants by slug rather
 * than truncating. It is safe to run on production (no demo data, no TRUNCATE).
 * Price is seeded as 0 — set actual prices via the admin panel.
 */
class PlantAtHomePlantBulkSeeder extends Seeder
{
    /** Path to the committed plant data JSON file. */
    private function dataPath(): string
    {
        return base_path('packages/marvel/data/plants.json');
    }

    private function boolField(?string $val): ?int
    {
        if ($val === null) return null;
        $v = strtolower(trim($val));
        if (in_array($v, ['true','yes','1','y'], true)) return 1;
        if (in_array($v, ['false','no','0','n'], true)) return 0;
        return null;
    }

    public function run(): void
    {
        $path = $this->dataPath();
        if (!file_exists($path)) {
            $this->command->error("[Tier 2] plants.json not found at {$path}");
            return;
        }

        $plants = json_decode(file_get_contents($path), true);
        if (empty($plants)) {
            $this->command->error('[Tier 2] plants.json is empty or malformed.');
            return;
        }

        $type = Type::where('slug', 'plants')->where('language', 'en')->first();
        if (!$type) {
            $this->command->warn('[Tier 2] Plants type not found — run PlantAtHomeTypeSeeder first.');
            return;
        }

        // Build category slug → id index (case-insensitive on slug)
        $categoryIndex = Category::where('language', 'en')
            ->where('type_id', $type->id)
            ->pluck('id', 'slug');

        $now     = now();
        $created = 0;
        $updated = 0;
        $errors  = 0;

        foreach ($plants as $p) {
            $name = trim($p['name'] ?? '');
            $slug = trim($p['slug'] ?? Str::slug($name));

            if (!$name || !$slug) {
                $errors++;
                continue;
            }

            try {
                $desc = $p['description'] ?? null;

                // ── products row ────────────────────────────────────────────
                [$product, $wasCreated] = tap(
                    Product::updateOrCreate(
                        ['slug' => $slug, 'language' => 'en'],
                        [
                            'name'         => $name,
                            'description'  => $desc,
                            'type_id'      => $type->id,
                            'language'     => 'en',
                            'status'       => 'publish',
                            'visibility'   => 'visibility_public',
                            'product_type' => 'simple',
                            'in_stock'     => true,
                            'is_taxable'   => false,
                            'unit'         => '1 Plant',
                            'price'        => 0,
                            'sale_price'   => 0,
                            'min_price'    => 0,
                            'max_price'    => 0,
                            'quantity'     => 0,   // admin sets actual stock
                            'image'        => null, // images to be sourced later
                            'gallery'      => null,
                            'updated_at'   => $now,
                        ]
                    ),
                    fn ($prod) => null
                );
                // Set created_at only on first insert (updateOrCreate doesn't preserve it)
                if (!$product->created_at) {
                    $product->created_at = $now;
                    $product->saveQuietly();
                }

                $wasCreated ? $created++ : $updated++;

                // ── plant_attributes row ────────────────────────────────────
                PlantAttribute::updateOrCreate(
                    ['product_id' => $product->id],
                    [
                        'scientific_name'  => $p['scientific_name'] ?? null,
                        'hindi_name'       => $p['hindi_name'] ?? null,
                        'indoor_outdoor'   => $p['indoor_outdoor'] ?? null,
                        'temperature_range'=> $p['temperature_range'] ?? null,
                        'height_range'     => $p['height_range'] ?? null,
                        'life_span'        => $p['life_span'] ?? null,
                        'sunlight'         => $p['sunlight'] ?? null,
                        'water_requirement'=> $p['water_requirement'] ?? null,
                        'benefits'         => $p['benefits'] ?? null,
                        'medicinal_uses'   => $p['medicinal_uses'] ?? null,
                        'air_purifying'    => isset($p['air_purifying']) ? ($p['air_purifying'] === true ? 1 : ($p['air_purifying'] === false ? 0 : null)) : null,
                        'pet_friendly'     => isset($p['pet_friendly']) ? ($p['pet_friendly'] === true ? 1 : ($p['pet_friendly'] === false ? 0 : null)) : null,
                        'growth_rate'      => $p['growth_rate'] ?? null,
                        'flowering_season' => $p['flowering_season'] ?? null,
                        'native_region'    => $p['native_region'] ?? null,
                    ]
                );

                // ── category pivot ──────────────────────────────────────────
                $catSlug = isset($p['category']) ? Str::slug($p['category']) : null;
                if ($catSlug && isset($categoryIndex[$catSlug])) {
                    $product->categories()->sync([$categoryIndex[$catSlug]]);
                } elseif ($catSlug) {
                    // category not yet seeded — warn once, skip
                    $this->command->warn("[Tier 2] Category not found: {$p['category']} (slug: {$catSlug}) for plant: {$name}");
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->command->warn("[Tier 2] Error on {$name}: " . $e->getMessage());
            }
        }

        $this->command->info(
            "[Tier 2] PlantAtHome bulk plants seeded — created: {$created}, updated: {$updated}, errors: {$errors} (total: " . count($plants) . ")"
        );
    }
}
