<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Restructures the PLANT category tree in place (type_id = Plants) and moves
 * characteristic-categories into the attribute system, per the taxonomy spec:
 * categories classify a plant; attributes describe it.
 *
 * Non-destructive and idempotent by design:
 *  - existing categories are matched BY SLUG and updated (never recreated), so every live
 *    /c/<slug> URL keeps working and product links stay attached;
 *  - "characteristic" categories (pet-friendly, air-purifying, medicinal) have their product
 *    links MIGRATED onto the matching plant fact — the wide-column flag where one already
 *    exists, else a Special Characteristics term — and are then deactivated, never deleted
 *    (orders, old links and analytics keep resolving);
 *  - genuinely new classifications are created.
 *
 * Tools / FarmBox / Pots verticals are untouched.
 */
class PlantTaxonomySeeder extends Seeder
{
    /** slug => [name, description] — the classification tree admins curate from here. */
    private const CATEGORIES = [
        'indoor'            => ['Indoor Plants', 'Plants that thrive inside the home or office.'],
        'outdoor'           => ['Outdoor Plants', 'Plants for gardens, balconies and terraces.'],
        'flowering'         => ['Flowering Plants', 'Plants grown for their blooms.'],
        'trees'             => ['Trees', 'Long-living woody plants for gardens and avenues.'],
        'palms-tropical'    => ['Palms & Tropical Plants', 'Palms and tropical foliage.'],
        'succulents-cacti'  => ['Succulents & Cacti', 'Water-storing plants that thrive on neglect.'],
        'herbs'             => ['Herbs', 'Culinary and aromatic herbs.'],
        'vegetable-plants'  => ['Vegetable Plants', 'Edible vegetable plants for the kitchen garden.'],
        'fruit-plants'      => ['Fruit Plants', 'Fruiting plants and saplings.'],
        'bonsai'            => ['Bonsai', 'Miniature trained trees.'],
        'aquatic-plants'    => ['Aquatic & Water Plants', 'Plants for ponds, bowls and water features.'],
        'climbers-vines'    => ['Climbers & Creepers', 'Vines and creepers for walls and trellises.'],
        'foliage'           => ['Foliage Plants', 'Grown for leaf colour, shape and texture.'],
        'landscaping'       => ['Landscaping Plants', 'Hedges, borders and ground cover.'],
        'rare-exotic'       => ['Rare & Exotic Plants', 'Collector and hard-to-find plants.'],
    ];

    /**
     * Characteristic categories → where that fact really belongs.
     * 'column' = an existing plant_attributes boolean; 'term' = a Special Characteristics term.
     */
    private const CHARACTERISTIC_CATEGORIES = [
        'pet-friendly'   => ['column' => 'pet_friendly'],
        'air-purifying'  => ['column' => 'air_purifying'],
        'medicinal'      => ['term' => 'Medicinal'],
    ];

    public function run(): void
    {
        $plantTypeId = DB::table('types')->where('slug', 'plants')->orWhere('name', 'Plants')->value('id');
        if (!$plantTypeId) {
            $this->command?->warn('Plants vertical not found — taxonomy seeding skipped.');
            return;
        }

        $this->migrateCharacteristicCategories($plantTypeId);
        $this->upsertCategories($plantTypeId);
    }

    /** Move characteristic-category membership onto the plant record itself, then retire it. */
    private function migrateCharacteristicCategories(int $plantTypeId): void
    {
        foreach (self::CHARACTERISTIC_CATEGORIES as $slug => $target) {
            $cat = DB::table('categories')->where('slug', $slug)->where('type_id', $plantTypeId)->first();
            if (!$cat) {
                continue;
            }
            $productIds = DB::table('category_product')->where('category_id', $cat->id)->pluck('product_id');
            if ($productIds->isNotEmpty()) {
                if (isset($target['column'])) {
                    $this->stampPlantColumn($productIds, $target['column']);
                } else {
                    $this->stampTerm($productIds, $target['term']);
                }
            }
            // Retire, never delete: the row keeps resolving for historical links.
            DB::table('categories')->where('id', $cat->id)->update([
                'is_active'         => false,
                'show_on_homepage'  => false,
                'details'           => 'Retired — this characteristic now lives on each plant as an attribute.',
                'updated_at'        => now(),
            ]);
            $this->command?->info("Retired characteristic category '{$slug}' ({$productIds->count()} plants mapped).");
        }
    }

    /** Set an existing plant_attributes boolean for every product in the list. */
    private function stampPlantColumn($productIds, string $column): void
    {
        if (!Schema::hasColumn('plant_attributes', $column)) {
            return;
        }
        foreach ($productIds->chunk(500) as $chunk) {
            // Only rows that exist; a plant with no botanical record gets one so the fact survives.
            $existing = DB::table('plant_attributes')->whereIn('product_id', $chunk)->pluck('product_id')->all();
            DB::table('plant_attributes')->whereIn('product_id', $existing)->update([$column => true, 'updated_at' => now()]);
            $missing = collect($chunk)->diff($existing);
            if ($missing->isNotEmpty()) {
                DB::table('plant_attributes')->insert($missing->map(fn ($pid) => [
                    'product_id' => $pid, $column => true, 'created_at' => now(), 'updated_at' => now(),
                ])->all());
            }
        }
    }

    /** Attach a Special Characteristics term to every product in the list. */
    private function stampTerm($productIds, string $termValue): void
    {
        $defId = DB::table('plant_attribute_definitions')->where('slug', 'special-characteristics')->value('id');
        if (!$defId) {
            return; // definitions seeder runs first in normal order; nothing to do otherwise
        }
        $termId = DB::table('plant_attribute_terms')
            ->where('definition_id', $defId)->where('slug', Str::slug($termValue))->value('id');
        if (!$termId) {
            $termId = DB::table('plant_attribute_terms')->insertGetId([
                'definition_id' => $defId, 'value' => $termValue, 'slug' => Str::slug($termValue),
                'sort' => 99, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ($productIds->chunk(500) as $chunk) {
            $have = DB::table('plant_attribute_product')->where('term_id', $termId)->whereIn('product_id', $chunk)->pluck('product_id')->all();
            $rows = collect($chunk)->diff($have)->map(fn ($pid) => [
                'definition_id' => $defId, 'term_id' => $termId, 'product_id' => $pid,
                'created_at' => now(), 'updated_at' => now(),
            ])->all();
            if ($rows) {
                DB::table('plant_attribute_product')->insert($rows);
            }
        }
    }

    /** Create/refresh the classification tree, matching on slug so URLs and links survive. */
    private function upsertCategories(int $plantTypeId): void
    {
        $order = 0;
        foreach (self::CATEGORIES as $slug => [$name, $description]) {
            $order += 10;
            $existing = DB::table('categories')->where('slug', $slug)->where('type_id', $plantTypeId)->first();
            if ($existing) {
                DB::table('categories')->where('id', $existing->id)->update([
                    'name'          => $name,
                    'details'       => $existing->details ?: $description,
                    'is_active'     => true,
                    'display_order' => $order,
                    'seo_title'     => $existing->seo_title ?? "{$name} — Buy Online | PlantAtHome",
                    'seo_description' => $existing->seo_description ?? $description,
                    'updated_at'    => now(),
                ]);
                continue;
            }
            DB::table('categories')->insert([
                'name'            => $name,
                'slug'            => $slug,
                'details'         => $description,
                'type_id'         => $plantTypeId,
                'language'        => defined('DEFAULT_LANGUAGE') ? DEFAULT_LANGUAGE : 'en',
                'is_active'       => true,
                'display_order'   => $order,
                'seo_title'       => "{$name} — Buy Online | PlantAtHome",
                'seo_description' => $description,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
        $this->command?->info('Plant classification tree upserted (' . count(self::CATEGORIES) . ' categories).');
    }
}
