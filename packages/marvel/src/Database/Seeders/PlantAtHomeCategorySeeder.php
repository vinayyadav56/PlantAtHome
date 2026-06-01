<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Type;

/**
 * Tier 2 — safe for all environments (updateOrCreate).
 * Seeds categories for all three verticals (plants / tools / farmbox), each
 * scoped to its type with a representative image so category cards render well.
 */
class PlantAtHomeCategorySeeder extends Seeder
{
    /** Unsplash helper → Pickbazar image json {id, original, thumbnail}. */
    private function img(int $id, string $photo): array
    {
        $base = "https://images.unsplash.com/photo-{$photo}?auto=format&fit=crop&q=80";
        return [
            'id'        => $id,
            'original'  => "{$base}&w=900",
            'thumbnail' => "{$base}&w=400",
        ];
    }

    private function categoriesByType(): array
    {
        return [
            'plants' => [
                ['name' => 'Indoor Plants',      'slug' => 'indoor-plants',    'icon' => 'Leaf',   'details' => 'Perfect for home and office spaces',     'photo' => '1545241047-6083a3684587'],
                ['name' => 'Outdoor Plants',     'slug' => 'outdoor-plants',   'icon' => 'Tree',   'details' => 'Hardy plants for gardens and balconies', 'photo' => '1416879595882-3373a0480b5b'],
                ['name' => 'Flowering Plants',   'slug' => 'flowering-plants', 'icon' => 'Flower', 'details' => 'Beautiful blooms for every season',      'photo' => '1490750967868-88aa4486c946'],
                ['name' => 'Succulents & Cacti', 'slug' => 'succulents-cacti', 'icon' => 'Cactus', 'details' => 'Low maintenance, high visual impact',     'photo' => '1459411552884-841db9b3cc2a'],
                ['name' => 'Air Purifying',      'slug' => 'air-purifying',    'icon' => 'Wind',   'details' => 'Plants that clean and freshen your air', 'photo' => '1593482892290-f54927ae1bb6'],
                ['name' => 'Gifts & Planters',   'slug' => 'gifts-planters',   'icon' => 'Gift',   'details' => 'Curated gift sets and premium planters', 'photo' => '1485955900006-10f4d324d411'],
            ],
            'tools' => [
                ['name' => 'Pruning & Cutting', 'slug' => 'pruning-cutting',  'icon' => 'Scissors', 'details' => 'Secateurs, shears and saws built to last',  'photo' => '1416879595882-3373a0480b5b'],
                ['name' => 'Watering',          'slug' => 'watering-tools',   'icon' => 'Droplet',  'details' => 'Cans, misters and precision watering',      'photo' => '1466692476868-aef1dfb1e735'],
                ['name' => 'Planters & Pots',   'slug' => 'planters-pots',    'icon' => 'Pot',      'details' => 'Terracotta, ceramic and designer planters', 'photo' => '1485955900006-10f4d324d411'],
                ['name' => 'Soil & Care',       'slug' => 'soil-care',        'icon' => 'Sprout',   'details' => 'Potting mixes, feeds and plant nutrition',  'photo' => '1416879595882-3373a0480b5b'],
                ['name' => 'Tool Sets',         'slug' => 'tool-sets',        'icon' => 'Toolbox',  'details' => 'Curated kits for every gardener',           'photo' => '1524419986249-348e8fa6ad4a'],
                ['name' => 'Accessories',       'slug' => 'tool-accessories', 'icon' => 'Glove',    'details' => 'Gloves, meters and finishing touches',      'photo' => '1459156212016-c812468e2115'],
            ],
            'farmbox' => [
                ['name' => 'Seasonal Veg Box',    'slug' => 'veg-box',           'icon' => 'Basket', 'details' => 'A weekly box of the freshest seasonal veg', 'photo' => '1542838132-92c53300491e'],
                ['name' => 'Fresh Fruits',        'slug' => 'fresh-fruits',      'icon' => 'Apple',  'details' => 'Hand-picked, sun-ripened fruit',           'photo' => '1619566636858-adf3ef46400b'],
                ['name' => 'Salad & Greens',      'slug' => 'salad-greens',      'icon' => 'Leaf',   'details' => 'Crisp leaves and ready-to-toss salad kits', 'photo' => '1540420773420-3366772f4999'],
                ['name' => 'Herbs',               'slug' => 'fresh-herbs',       'icon' => 'Sprout', 'details' => 'Aromatic, just-cut culinary herbs',         'photo' => '1466692476868-aef1dfb1e735'],
                ['name' => 'Exotic Picks',        'slug' => 'exotic-picks',      'icon' => 'Star',   'details' => 'Rare and exotic seasonal produce',          'photo' => '1619566636858-adf3ef46400b'],
                ['name' => 'Juices & Cold-press', 'slug' => 'juices-cold-press', 'icon' => 'Cup',    'details' => 'Cold-pressed juices, no added sugar',       'photo' => '1542838132-92c53300491e'],
            ],
        ];
    }

    public function run(): void
    {
        $imgId = 9000;
        $total = 0;

        foreach ($this->categoriesByType() as $typeSlug => $cats) {
            $type = Type::where('slug', $typeSlug)->where('language', 'en')->first();
            if (!$type) {
                $this->command->warn("[Tier 2] Type '{$typeSlug}' not found — run PlantAtHomeTypeSeeder first.");
                continue;
            }

            foreach ($cats as $cat) {
                Category::updateOrCreate(
                    ['slug' => $cat['slug'], 'language' => 'en'],
                    [
                        'name'     => $cat['name'],
                        'icon'     => $cat['icon'],
                        'details'  => $cat['details'],
                        'type_id'  => $type->id,
                        'language' => 'en',
                        'parent'   => null,
                        'image'    => $this->img(++$imgId, $cat['photo']),
                    ]
                );
                $total++;
            }
        }

        $this->command->info("[Tier 2] PlantAtHome categories seeded across 3 verticals: {$total}");
    }
}
