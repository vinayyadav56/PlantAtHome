<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Type;
use Marvel\Database\Models\Category;

/**
 * STAGING ONLY — Tier 3 demo data.
 * Truncates and re-inserts sample plant products.
 * Never called in production (see PlantAtHomeSeeder).
 */
class PlantAtHomeProductSeeder extends Seeder
{
    private array $products = [
        ['name' => 'Monstera Deliciosa', 'slug' => 'monstera-deliciosa', 'price' => 1299, 'sale_price' => 999,  'quantity' => 50,  'unit' => '1 Plant', 'sku' => 'PAH-MONSTE-001', 'description' => 'The iconic split-leaf plant — bold, architectural, and perfect for bright interiors. A must-have for modern homes.'],
        ['name' => 'Peace Lily',          'slug' => 'peace-lily',          'price' => 649,  'sale_price' => 549,  'quantity' => 100, 'unit' => '1 Plant', 'sku' => 'PAH-PEACEL-001', 'description' => 'Elegant white blooms, excellent air purifier, thrives in low light. One of the easiest flowering plants to care for.'],
        ['name' => 'Snake Plant',         'slug' => 'snake-plant',         'price' => 799,  'sale_price' => 699,  'quantity' => 80,  'unit' => '1 Plant', 'sku' => 'PAH-SNAKEP-001', 'description' => 'Nearly indestructible, filters indoor air toxins. Thrives in low light and needs minimal watering.'],
        ['name' => 'Golden Pothos',       'slug' => 'golden-pothos',       'price' => 399,  'sale_price' => 349,  'quantity' => 150, 'unit' => '1 Plant', 'sku' => 'PAH-GOLDPO-001', 'description' => 'Cascading vines with heart-shaped leaves, thrives in any light condition.'],
        ['name' => 'Fiddle Leaf Fig',     'slug' => 'fiddle-leaf-fig',     'price' => 1499, 'sale_price' => 1299, 'quantity' => 30,  'unit' => '1 Plant', 'sku' => 'PAH-FIDDLF-001', 'description' => 'Statement tree with large violin-shaped leaves — the interior design favourite.'],
        ['name' => 'Areca Palm',          'slug' => 'areca-palm',          'price' => 899,  'sale_price' => 799,  'quantity' => 60,  'unit' => '1 Plant', 'sku' => 'PAH-ARECP-001',  'description' => 'Tropical elegance, natural humidifier, great for living rooms and lobbies.'],
        ['name' => 'ZZ Plant',            'slug' => 'zz-plant',            'price' => 549,  'sale_price' => 499,  'quantity' => 90,  'unit' => '1 Plant', 'sku' => 'PAH-ZZPLAN-001', 'description' => 'Glossy dark-green leaves, extremely drought tolerant.'],
        ['name' => 'Money Plant',         'slug' => 'money-plant',         'price' => 299,  'sale_price' => 249,  'quantity' => 200, 'unit' => '1 Plant', 'sku' => 'PAH-MONEYP-001', 'description' => 'Classic Indian favourite — believed to bring prosperity. Easy to grow in water or soil.'],
        ['name' => 'Aloe Vera',           'slug' => 'aloe-vera',           'price' => 349,  'sale_price' => 299,  'quantity' => 120, 'unit' => '1 Plant', 'sku' => 'PAH-ALOEVE-001', 'description' => 'Medicinal succulent with soothing gel, very low maintenance.'],
        ['name' => 'Bird of Paradise',    'slug' => 'bird-of-paradise',    'price' => 1999, 'sale_price' => 1799, 'quantity' => 20,  'unit' => '1 Plant', 'sku' => 'PAH-BIRDOP-001', 'description' => 'Dramatic tropical leaves, makes a bold statement in any room or entrance.'],
        ['name' => 'Spider Plant',        'slug' => 'spider-plant',        'price' => 349,  'sale_price' => 299,  'quantity' => 100, 'unit' => '1 Plant', 'sku' => 'PAH-SPIDER-001', 'description' => 'Air-purifying champion, pet-friendly, produces baby plants effortlessly.'],
        ['name' => 'Rubber Plant',        'slug' => 'rubber-plant',        'price' => 899,  'sale_price' => 799,  'quantity' => 45,  'unit' => '1 Plant', 'sku' => 'PAH-RUBBER-001', 'description' => 'Bold burgundy or dark-green leaves, grows tall. Excellent for contemporary interiors.'],
        ['name' => 'Jade Plant',          'slug' => 'jade-plant',          'price' => 499,  'sale_price' => 449,  'quantity' => 70,  'unit' => '1 Plant', 'sku' => 'PAH-JADEP-001',  'description' => 'Succulent bonsai-like shrub, long-lived, easy care, symbol of good luck.'],
        ['name' => 'Bougainvillea',       'slug' => 'bougainvillea',       'price' => 599,  'sale_price' => 499,  'quantity' => 55,  'unit' => '1 Plant', 'sku' => 'PAH-BOUGN-001',  'description' => 'Vibrant magenta bracts, perfect for balconies and garden walls.'],
        ['name' => 'Anthurium',           'slug' => 'anthurium',           'price' => 799,  'sale_price' => 699,  'quantity' => 65,  'unit' => '1 Plant', 'sku' => 'PAH-ANTHU-001',  'description' => 'Heart-shaped glossy red spathes, long-lasting blooms, air-purifying.'],
    ];

    // product slug → [category slugs]
    private array $categoryMap = [
        'monstera-deliciosa' => ['indoor-plants'],
        'peace-lily'         => ['indoor-plants', 'flowering-plants', 'air-purifying'],
        'snake-plant'        => ['indoor-plants', 'air-purifying'],
        'golden-pothos'      => ['indoor-plants', 'air-purifying'],
        'fiddle-leaf-fig'    => ['indoor-plants'],
        'areca-palm'         => ['indoor-plants', 'air-purifying'],
        'zz-plant'           => ['indoor-plants', 'succulents-cacti'],
        'money-plant'        => ['indoor-plants'],
        'aloe-vera'          => ['succulents-cacti'],
        'bird-of-paradise'   => ['outdoor-plants'],
        'spider-plant'       => ['indoor-plants', 'air-purifying'],
        'rubber-plant'       => ['indoor-plants'],
        'jade-plant'         => ['succulents-cacti'],
        'bougainvillea'      => ['outdoor-plants', 'flowering-plants'],
        'anthurium'          => ['flowering-plants'],
    ];

    public function run(): void
    {
        $type = Type::where('slug', 'plants')->where('language', 'en')->first();

        if (!$type) {
            $this->command->warn('[Tier 3] Plants type not found — skipping product seed.');
            return;
        }

        // Safe truncate — staging only, no real orders against these demo products
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('order_product')->truncate();
        DB::table('reviews')->truncate();
        DB::table('questions')->truncate();
        DB::table('category_product')->truncate();
        DB::table('products')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $categoryIndex = Category::where('language', 'en')
            ->whereIn('slug', array_keys(array_merge(...array_values($this->categoryMap))))
            ->pluck('id', 'slug');

        $now = now();
        foreach ($this->products as $p) {
            $product = Product::create(array_merge($p, [
                'type_id'      => $type->id,
                'language'     => 'en',
                'status'       => 'publish',
                'visibility'   => 'visibility_public',
                'product_type' => 'simple',
                'in_stock'     => true,
                'is_taxable'   => false,
                'min_price'    => $p['sale_price'],
                'max_price'    => $p['price'],
                'created_at'   => $now,
                'updated_at'   => $now,
            ]));

            $catSlugs = $this->categoryMap[$p['slug']] ?? [];
            $catIds   = array_filter(array_map(fn($s) => $categoryIndex[$s] ?? null, $catSlugs));
            if ($catIds) {
                $product->categories()->sync($catIds);
            }
        }

        $this->command->info('[Tier 3] PlantAtHome demo products seeded: ' . count($this->products) . ' (staging only)');
    }
}
