<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Type;
use Marvel\Database\Models\Category;

/**
 * STAGING ONLY — Tier 3 demo data.
 * Truncates and re-inserts sample products across all three verticals
 * (plants / tools / farmbox), each with images and category mappings.
 * Never called in production (see PlantAtHomeSeeder).
 */
class PlantAtHomeProductSeeder extends Seeder
{
    /** Unsplash helper → Pickbazar image json {id, original, thumbnail}. */
    private function img(int $id, string $photo): array
    {
        $base = "https://images.unsplash.com/photo-{$photo}?auto=format&fit=crop&q=80";
        return [
            'id'        => $id,
            'original'  => "{$base}&w=900",
            'thumbnail' => "{$base}&w=600",
        ];
    }

    /** typeSlug => [ products… ]  (price = MRP, sale_price = selling price). */
    private function productsByType(): array
    {
        return [
            'plants' => [
                ['name' => 'Monstera Deliciosa', 'slug' => 'monstera-deliciosa', 'price' => 1299, 'sale_price' => 999,  'quantity' => 50,  'unit' => '1 Plant', 'sku' => 'PAH-MONSTE-001', 'photo' => '1614594975525-e45190c55d0b', 'cats' => ['indoor-plants'],                                   'description' => 'The iconic split-leaf plant — bold, architectural, and perfect for bright interiors. A must-have for modern homes.'],
                ['name' => 'Peace Lily',         'slug' => 'peace-lily',         'price' => 649,  'sale_price' => 549,  'quantity' => 100, 'unit' => '1 Plant', 'sku' => 'PAH-PEACEL-001', 'photo' => '1593691509543-c55fb32d8de5', 'cats' => ['indoor-plants', 'flowering-plants', 'air-purifying'], 'description' => 'Elegant white blooms, excellent air purifier, thrives in low light. One of the easiest flowering plants to care for.'],
                ['name' => 'Snake Plant',        'slug' => 'snake-plant',        'price' => 799,  'sale_price' => 699,  'quantity' => 80,  'unit' => '1 Plant', 'sku' => 'PAH-SNAKEP-001', 'photo' => '1593482892290-f54927ae1bb6', 'cats' => ['indoor-plants', 'air-purifying'],                  'description' => 'Nearly indestructible, filters indoor air toxins. Thrives in low light and needs minimal watering.'],
                ['name' => 'Golden Pothos',      'slug' => 'golden-pothos',      'price' => 399,  'sale_price' => 349,  'quantity' => 150, 'unit' => '1 Plant', 'sku' => 'PAH-GOLDPO-001', 'photo' => '1622547748225-3fc4abd2cca0', 'cats' => ['indoor-plants', 'air-purifying'],                  'description' => 'Cascading vines with heart-shaped leaves, thrives in any light condition.'],
                ['name' => 'Fiddle Leaf Fig',    'slug' => 'fiddle-leaf-fig',    'price' => 1499, 'sale_price' => 1299, 'quantity' => 30,  'unit' => '1 Plant', 'sku' => 'PAH-FIDDLF-001', 'photo' => '1597055181300-e3633a917b6f', 'cats' => ['indoor-plants'],                                   'description' => 'Statement tree with large violin-shaped leaves — the interior design favourite.'],
                ['name' => 'Areca Palm',         'slug' => 'areca-palm',         'price' => 899,  'sale_price' => 799,  'quantity' => 60,  'unit' => '1 Plant', 'sku' => 'PAH-ARECP-001',  'photo' => '1446071103084-c257b5f70672', 'cats' => ['indoor-plants', 'air-purifying'],                  'description' => 'Tropical elegance, natural humidifier, great for living rooms and lobbies.'],
                ['name' => 'ZZ Plant',           'slug' => 'zz-plant',           'price' => 549,  'sale_price' => 499,  'quantity' => 90,  'unit' => '1 Plant', 'sku' => 'PAH-ZZPLAN-001', 'photo' => '1632207691143-643e2a9a9361', 'cats' => ['indoor-plants', 'succulents-cacti'],               'description' => 'Glossy dark-green leaves, extremely drought tolerant.'],
                ['name' => 'Money Plant',        'slug' => 'money-plant',        'price' => 299,  'sale_price' => 249,  'quantity' => 200, 'unit' => '1 Plant', 'sku' => 'PAH-MONEYP-001', 'photo' => '1622547748225-3fc4abd2cca0', 'cats' => ['indoor-plants'],                                   'description' => 'Classic Indian favourite — believed to bring prosperity. Easy to grow in water or soil.'],
                ['name' => 'Aloe Vera',          'slug' => 'aloe-vera',          'price' => 349,  'sale_price' => 299,  'quantity' => 120, 'unit' => '1 Plant', 'sku' => 'PAH-ALOEVE-001', 'photo' => '1509423350716-97f2360af2e4', 'cats' => ['succulents-cacti'],                                'description' => 'Medicinal succulent with soothing gel, very low maintenance.'],
                ['name' => 'Bird of Paradise',   'slug' => 'bird-of-paradise',   'price' => 1999, 'sale_price' => 1799, 'quantity' => 20,  'unit' => '1 Plant', 'sku' => 'PAH-BIRDOP-001', 'photo' => '1597055181300-e3633a917b6f', 'cats' => ['outdoor-plants'],                                  'description' => 'Dramatic tropical leaves, makes a bold statement in any room or entrance.'],
                ['name' => 'Spider Plant',       'slug' => 'spider-plant',       'price' => 349,  'sale_price' => 299,  'quantity' => 100, 'unit' => '1 Plant', 'sku' => 'PAH-SPIDER-001', 'photo' => '1593482892290-f54927ae1bb6', 'cats' => ['indoor-plants', 'air-purifying'],                  'description' => 'Air-purifying champion, pet-friendly, produces baby plants effortlessly.'],
                ['name' => 'Rubber Plant',       'slug' => 'rubber-plant',       'price' => 899,  'sale_price' => 799,  'quantity' => 45,  'unit' => '1 Plant', 'sku' => 'PAH-RUBBER-001', 'photo' => '1632207691143-643e2a9a9361', 'cats' => ['indoor-plants'],                                   'description' => 'Bold burgundy or dark-green leaves, grows tall. Excellent for contemporary interiors.'],
                ['name' => 'Jade Plant',         'slug' => 'jade-plant',         'price' => 499,  'sale_price' => 449,  'quantity' => 70,  'unit' => '1 Plant', 'sku' => 'PAH-JADEP-001',  'photo' => '1509423350716-97f2360af2e4', 'cats' => ['succulents-cacti'],                                'description' => 'Succulent bonsai-like shrub, long-lived, easy care, symbol of good luck.'],
                ['name' => 'Bougainvillea',      'slug' => 'bougainvillea',      'price' => 599,  'sale_price' => 499,  'quantity' => 55,  'unit' => '1 Plant', 'sku' => 'PAH-BOUGN-001',  'photo' => '1490750967868-88aa4486c946', 'cats' => ['outdoor-plants', 'flowering-plants'],              'description' => 'Vibrant magenta bracts, perfect for balconies and garden walls.'],
                ['name' => 'Anthurium',          'slug' => 'anthurium',          'price' => 799,  'sale_price' => 699,  'quantity' => 65,  'unit' => '1 Plant', 'sku' => 'PAH-ANTHU-001',  'photo' => '1593691509543-c55fb32d8de5', 'cats' => ['flowering-plants'],                                'description' => 'Heart-shaped glossy red spathes, long-lasting blooms, air-purifying.'],
            ],
            'tools' => [
                ['name' => 'Brass Secateurs',         'slug' => 'brass-secateurs',         'price' => 1699, 'sale_price' => 1299, 'quantity' => 60,  'unit' => '1 Pair',   'sku' => 'PAH-TOOL-001', 'photo' => '1416879595882-3373a0480b5b', 'cats' => ['pruning-cutting'],   'description' => 'Precision forged-brass secateurs with a sap groove and replaceable blade — a lifetime companion for clean cuts.'],
                ['name' => 'Copper Watering Can',     'slug' => 'copper-watering-can',     'price' => 2399, 'sale_price' => 1899, 'quantity' => 40,  'unit' => '1 Can',    'sku' => 'PAH-TOOL-002', 'photo' => '1466692476868-aef1dfb1e735', 'cats' => ['watering-tools'],    'description' => 'Hand-finished copper watering can with a long brass rose for a soft, even pour. Ages into a beautiful patina.'],
                ['name' => 'Hand Trowel — Oak',       'slug' => 'hand-trowel-oak',         'price' => 1199, 'sale_price' => 899,  'quantity' => 80,  'unit' => '1 Tool',   'sku' => 'PAH-TOOL-003', 'photo' => '1524419986249-348e8fa6ad4a', 'cats' => ['soil-care'],         'description' => 'Stainless-steel trowel set in an FSC-oak handle — balanced, comfortable and built for daily potting.'],
                ['name' => 'Terracotta Planter Set',  'slug' => 'terracotta-planter-set',  'price' => 1999, 'sale_price' => 1499, 'quantity' => 35,  'unit' => 'Set of 3', 'sku' => 'PAH-TOOL-004', 'photo' => '1485955900006-10f4d324d411', 'cats' => ['planters-pots'],     'description' => 'A trio of hand-thrown terracotta planters with drainage and matching saucers — for breathable, healthy roots.'],
                ['name' => 'Pruning Saw',             'slug' => 'pruning-saw',             'price' => 1399, 'sale_price' => 1099, 'quantity' => 45,  'unit' => '1 Saw',    'sku' => 'PAH-TOOL-005', 'photo' => '1416879595882-3373a0480b5b', 'cats' => ['pruning-cutting'],   'description' => 'Razor-tooth folding pruning saw that glides through thick stems and small branches with ease.'],
                ['name' => 'Gardening Glove — Leather','slug' => 'gardening-glove-leather', 'price' => 899,  'sale_price' => 699,  'quantity' => 120, 'unit' => '1 Pair',   'sku' => 'PAH-TOOL-006', 'photo' => '1459156212016-c812468e2115', 'cats' => ['tool-accessories'],  'description' => 'Full-grain leather gloves with reinforced palms — thorn-proof protection that softens with use.'],
                ['name' => 'Moisture Meter',          'slug' => 'moisture-meter',          'price' => 999,  'sale_price' => 799,  'quantity' => 90,  'unit' => '1 Meter',  'sku' => 'PAH-TOOL-007', 'photo' => '1466692476868-aef1dfb1e735', 'cats' => ['tool-accessories'],  'description' => 'No-battery soil moisture probe — never over- or under-water again. Reads instantly at the root zone.'],
                ['name' => 'Essential Tool Kit',      'slug' => 'essential-tool-kit',      'price' => 3299, 'sale_price' => 2499, 'quantity' => 30,  'unit' => '6-pc Kit', 'sku' => 'PAH-TOOL-008', 'photo' => '1524419986249-348e8fa6ad4a', 'cats' => ['tool-sets'],         'description' => 'Everything a new plant parent needs — trowel, fork, pruner, mister, gloves and a waxed-canvas roll.'],
            ],
            'farmbox' => [
                ['name' => 'Weekly Organic Box',    'slug' => 'weekly-organic-box',    'price' => 999,  'sale_price' => 799,  'quantity' => 200, 'unit' => '1 Box',     'sku' => 'PAH-FARM-001', 'photo' => '1542838132-92c53300491e', 'cats' => ['veg-box'],           'description' => 'A generous box of seasonal organic vegetables, harvested at dawn and delivered the same day. Feeds 2–3.'],
                ['name' => 'Fresh Salad Kit',       'slug' => 'fresh-salad-kit',       'price' => 399,  'sale_price' => 299,  'quantity' => 250, 'unit' => '1 Kit',     'sku' => 'PAH-FARM-002', 'photo' => '1540420773420-3366772f4999', 'cats' => ['salad-greens'],      'description' => 'Crisp washed leaves, cherry tomatoes, cucumber and a house dressing — toss and serve in minutes.'],
                ['name' => 'Seasonal Fruit Basket', 'slug' => 'seasonal-fruit-basket', 'price' => 849,  'sale_price' => 649,  'quantity' => 150, 'unit' => '1 Basket',  'sku' => 'PAH-FARM-003', 'photo' => '1619566636858-adf3ef46400b', 'cats' => ['fresh-fruits'],      'description' => 'A hand-picked basket of the best sun-ripened fruit in season — sweet, fragrant and never cold-stored.'],
                ['name' => 'Leafy Greens Bundle',   'slug' => 'leafy-greens-bundle',   'price' => 349,  'sale_price' => 249,  'quantity' => 220, 'unit' => '1 Bundle',  'sku' => 'PAH-FARM-004', 'photo' => '1540420773420-3366772f4999', 'cats' => ['salad-greens', 'fresh-herbs'], 'description' => 'Spinach, kale, methi and amaranth — a nutrient-dense bundle of just-cut leafy greens and herbs.'],
                ['name' => 'Heirloom Tomatoes',     'slug' => 'heirloom-tomatoes',     'price' => 269,  'sale_price' => 199,  'quantity' => 180, 'unit' => '500 g',     'sku' => 'PAH-FARM-005', 'photo' => '1592924357228-91a4daadcfea', 'cats' => ['veg-box'],           'description' => 'Vine-ripened heirloom tomatoes in a riot of colour — deep flavour you can only get farm-fresh.'],
                ['name' => 'Cold-press Juice Pack', 'slug' => 'cold-press-juice-pack', 'price' => 649,  'sale_price' => 499,  'quantity' => 140, 'unit' => 'Pack of 4', 'sku' => 'PAH-FARM-006', 'photo' => '1542838132-92c53300491e', 'cats' => ['juices-cold-press'], 'description' => 'Four cold-pressed juices pressed the morning of delivery — no added sugar, no concentrate, just fruit.'],
                ['name' => 'Exotic Veg Box',        'slug' => 'exotic-veg-box',        'price' => 1149, 'sale_price' => 899,  'quantity' => 90,  'unit' => '1 Box',     'sku' => 'PAH-FARM-007', 'photo' => '1619566636858-adf3ef46400b', 'cats' => ['exotic-picks'],      'description' => 'Zucchini, bell peppers, asparagus, broccolini and more — a curated box of exotic seasonal produce.'],
                ['name' => 'Family Organic Box',    'slug' => 'family-organic-box',    'price' => 1699, 'sale_price' => 1299, 'quantity' => 110, 'unit' => '1 Box',     'sku' => 'PAH-FARM-008', 'photo' => '1542838132-92c53300491e', 'cats' => ['veg-box'],           'description' => 'Our largest weekly box of organic vegetables and greens — enough to feed a family of four all week.'],
            ],
        ];
    }

    public function run(): void
    {
        // Safe truncate — staging only, no real orders against these demo products
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('order_product')->truncate();
        DB::table('reviews')->truncate();
        DB::table('questions')->truncate();
        DB::table('category_product')->truncate();
        DB::table('products')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $categoryIndex = Category::where('language', 'en')->pluck('id', 'slug');

        $now   = now();
        $imgId = 8000;
        $count = 0;

        foreach ($this->productsByType() as $typeSlug => $products) {
            $type = Type::where('slug', $typeSlug)->where('language', 'en')->first();
            if (!$type) {
                $this->command->warn("[Tier 3] Type '{$typeSlug}' not found — skipping its products.");
                continue;
            }

            foreach ($products as $p) {
                $catSlugs = $p['cats'];
                $photo    = $p['photo'];
                unset($p['cats'], $p['photo']);

                $product = Product::create(array_merge($p, [
                    'type_id'      => $type->id,
                    'language'     => 'en',
                    'status'       => 'publish',
                    'visibility'   => 'visibility_public',
                    'product_type' => 'simple',
                    'in_stock'     => true,
                    'is_taxable'   => false,
                    'image'        => $this->img(++$imgId, $photo),
                    'min_price'    => $p['sale_price'],
                    'max_price'    => $p['price'],
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]));

                $catIds = array_filter(array_map(fn($s) => $categoryIndex[$s] ?? null, $catSlugs));
                if ($catIds) {
                    $product->categories()->sync($catIds);
                }
                $count++;
            }
        }

        $this->command->info("[Tier 3] PlantAtHome demo products seeded across 3 verticals: {$count} (staging only)");
    }
}
