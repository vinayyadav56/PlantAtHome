<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Type;

class PlantAtHomeCategorySeeder extends Seeder
{
    private array $categories = [
        ['name' => 'Indoor Plants',      'slug' => 'indoor-plants',    'icon' => 'Leaf',   'details' => 'Perfect for home and office spaces'],
        ['name' => 'Outdoor Plants',     'slug' => 'outdoor-plants',   'icon' => 'Tree',   'details' => 'Hardy plants for gardens and balconies'],
        ['name' => 'Flowering Plants',   'slug' => 'flowering-plants', 'icon' => 'Flower', 'details' => 'Beautiful blooms for every season'],
        ['name' => 'Succulents & Cacti', 'slug' => 'succulents-cacti', 'icon' => 'Cactus', 'details' => 'Low maintenance, high visual impact'],
        ['name' => 'Air Purifying',      'slug' => 'air-purifying',    'icon' => 'Wind',   'details' => 'Plants that clean and freshen your air'],
        ['name' => 'Gifts & Planters',   'slug' => 'gifts-planters',   'icon' => 'Gift',   'details' => 'Curated gift sets and premium planters'],
    ];

    public function run(): void
    {
        $type = Type::where('slug', 'plants')->where('language', 'en')->first();

        if (!$type) {
            $this->command->warn('[Tier 2] Plants type not found — run PlantAtHomeTypeSeeder first.');
            return;
        }

        foreach ($this->categories as $cat) {
            Category::updateOrCreate(
                ['slug' => $cat['slug'], 'language' => 'en'],
                array_merge($cat, [
                    'type_id' => $type->id,
                    'language' => 'en',
                    'parent'   => null,
                    'image'    => null,
                ])
            );
        }

        $this->command->info('[Tier 2] PlantAtHome categories seeded: ' . count($this->categories));
    }
}
