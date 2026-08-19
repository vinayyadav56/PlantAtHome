<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the DISCOVERY attributes admins extend at runtime.
 *
 * Deliberately excludes light / water / difficulty / growth-rate / size / environment /
 * air-purifying / pet-friendly: those are already live as `plant_attributes` columns with
 * facets, filters and a PDP. Duplicating them here would create two answers to the same
 * question. What is seeded here is what the platform had NO home for.
 */
class PlantAttributeDefinitionSeeder extends Seeder
{
    private const DEFINITIONS = [
        [
            'name' => 'Suitable Spaces', 'slug' => 'suitable-spaces', 'type' => 'multi', 'sort' => 10,
            'terms' => ['Bedroom', 'Living Room', 'Office', 'Desk', 'Balcony', 'Terrace', 'Garden', 'Bathroom', 'Entryway', 'Commercial Space'],
        ],
        [
            'name' => 'Special Characteristics', 'slug' => 'special-characteristics', 'type' => 'multi', 'sort' => 20,
            'terms' => ['Fragrant', 'Low Maintenance', 'Fast Growing', 'Rare', 'Exotic', 'Beginner Friendly', 'Medicinal'],
        ],
    ];

    public function run(): void
    {
        foreach (self::DEFINITIONS as $def) {
            $id = DB::table('plant_attribute_definitions')->where('slug', $def['slug'])->value('id');
            if (!$id) {
                $id = DB::table('plant_attribute_definitions')->insertGetId([
                    'name' => $def['name'], 'slug' => $def['slug'], 'type' => $def['type'],
                    'is_facet' => true, 'is_active' => true, 'sort' => $def['sort'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $sort = 0;
            foreach ($def['terms'] as $value) {
                $sort += 10;
                $slug = Str::slug($value);
                $exists = DB::table('plant_attribute_terms')->where('definition_id', $id)->where('slug', $slug)->exists();
                if (!$exists) {
                    DB::table('plant_attribute_terms')->insert([
                        'definition_id' => $id, 'value' => $value, 'slug' => $slug,
                        'sort' => $sort, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
        $this->command?->info('Plant attribute definitions seeded.');
    }
}
