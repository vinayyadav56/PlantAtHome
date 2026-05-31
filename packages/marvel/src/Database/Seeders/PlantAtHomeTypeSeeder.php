<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\Type;

class PlantAtHomeTypeSeeder extends Seeder
{
    public function run(): void
    {
        Type::updateOrCreate(
            ['slug' => 'plants', 'language' => 'en'],
            [
                'name'     => 'Plants',
                'icon'     => 'Leaf',
                'settings' => [
                    'isHome'      => true,
                    'layoutType'  => 'classic',
                    'productCard' => 'argon',
                ],
                'promotional_sliders' => null,
            ]
        );

        $this->command->info('[Tier 2] PlantAtHome type seeded: plants (isHome: true)');
    }
}
