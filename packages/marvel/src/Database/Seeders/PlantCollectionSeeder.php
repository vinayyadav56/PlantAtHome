<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dynamic collections — each is just a saved set of listing filters, so a collection page
 * and the equivalent filtered listing can never disagree, and no product is duplicated to
 * appear in one.
 */
class PlantCollectionSeeder extends Seeder
{
    private const COLLECTIONS = [
        ['Air Purifying Plants', 'air-purifying-plants', 'Plants that quietly clean the air you breathe.', ['air_purifying' => 'true']],
        ['Pet Friendly Plants', 'pet-friendly-plants', 'Safe to keep around cats and dogs.', ['pet_friendly' => 'true']],
        ['Low Light Plants', 'low-light-plants', 'Happy in corners the sun never reaches.', ['sunlight' => 'Low Light']],
        ['Beginner Plants', 'beginner-plants', 'Hard to kill, easy to love.', ['difficulty' => 'Beginner']],
        ['Low Maintenance Plants', 'low-maintenance-plants', 'Thrive on a little neglect.', ['special' => 'low-maintenance']],
        ['Bedroom Plants', 'bedroom-plants', 'Calm, quiet greenery for where you sleep.', ['space' => 'bedroom']],
        ['Office Plants', 'office-plants', 'Desk-friendly plants that survive the workweek.', ['space' => 'office,desk']],
        ['Flowering Plants', 'flowering-plants', 'Colour that comes back season after season.', ['category' => 'flowering']],
        ['Rare Plants', 'rare-plants', 'Collector pieces, in limited supply.', ['special' => 'rare,exotic']],
    ];

    public function run(): void
    {
        $sort = 0;
        foreach (self::COLLECTIONS as [$name, $slug, $description, $rules]) {
            $sort += 10;
            if (DB::table('plant_collections')->where('slug', $slug)->exists()) {
                continue;
            }
            DB::table('plant_collections')->insert([
                'name' => $name, 'slug' => $slug, 'description' => $description,
                'rules' => json_encode($rules), 'is_active' => true, 'sort' => $sort,
                'seo_title' => "{$name} | PlantAtHome", 'seo_description' => $description,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->command?->info('Plant collections seeded.');
    }
}
