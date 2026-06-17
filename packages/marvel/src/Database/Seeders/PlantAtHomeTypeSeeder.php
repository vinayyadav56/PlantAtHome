<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\Type;

/**
 * Tier 2 — safe for all environments (updateOrCreate).
 * Seeds the PlantAtHome verticals (types): Plants (home), Tools, FarmBox, plus the
 * marketplace catalogs Fertilizers, Pots & Planters and Seeds. Each shares the single
 * premium storefront layout (the shop renders every brand type with the immersive layout).
 */
class PlantAtHomeTypeSeeder extends Seeder
{
    private array $types = [
        ['slug' => 'plants',         'name' => 'Plants',           'icon' => 'Leaf',   'isHome' => true],
        ['slug' => 'tools',          'name' => 'Tools',            'icon' => 'Tool',   'isHome' => false],
        ['slug' => 'farmbox',        'name' => 'FarmBox',          'icon' => 'Basket', 'isHome' => false],
        ['slug' => 'fertilizers',    'name' => 'Fertilizers',      'icon' => 'Leaf',   'isHome' => false],
        ['slug' => 'pots-planters',  'name' => 'Pots & Planters',  'icon' => 'Basket', 'isHome' => false],
        ['slug' => 'seeds',          'name' => 'Seeds',            'icon' => 'Leaf',   'isHome' => false],
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

        $this->command->info('[Tier 2] PlantAtHome types seeded: plants (home), tools, farmbox, fertilizers, pots-planters, seeds');
    }
}
