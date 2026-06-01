<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\Type;

/**
 * Tier 2 — safe for all environments (updateOrCreate).
 * Seeds the three PlantAtHome verticals (types): Plants (home), Tools, FarmBox.
 * Each shares the single premium storefront layout (the shop renders every
 * brand type with the PlantAtHome immersive layout).
 */
class PlantAtHomeTypeSeeder extends Seeder
{
    private array $types = [
        ['slug' => 'plants',  'name' => 'Plants',  'icon' => 'Leaf',   'isHome' => true],
        ['slug' => 'tools',   'name' => 'Tools',   'icon' => 'Tool',   'isHome' => false],
        ['slug' => 'farmbox', 'name' => 'FarmBox', 'icon' => 'Basket', 'isHome' => false],
    ];

    public function run(): void
    {
        foreach ($this->types as $t) {
            Type::updateOrCreate(
                ['slug' => $t['slug'], 'language' => 'en'],
                [
                    'name'     => $t['name'],
                    'icon'     => $t['icon'],
                    'settings' => [
                        'isHome'      => $t['isHome'],
                        'layoutType'  => 'classic',
                        'productCard' => 'argon',
                    ],
                    'promotional_sliders' => null,
                ]
            );
        }

        $this->command->info('[Tier 2] PlantAtHome types seeded: plants (home), tools, farmbox');
    }
}
