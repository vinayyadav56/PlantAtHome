<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orchestrates all PlantAtHome seeders.
 *
 * Tier 2 (type + categories): runs in ALL environments via updateOrCreate — safe, idempotent.
 * Tier 3 (demo products):     runs in STAGING only — truncates, never touches production.
 *
 * Usage:
 *   php artisan db:seed --class="Marvel\Database\Seeders\PlantAtHomeSeeder" --force
 */
class PlantAtHomeSeeder extends Seeder
{
    public function run(): void
    {
        $env = app()->environment();
        $this->command->info("PlantAtHomeSeeder running (APP_ENV={$env})");

        // Tier 2 — both envs
        $this->call(PlantAtHomeTypeSeeder::class);
        $this->call(PlantAtHomeCategorySeeder::class);      // original 18 demo categories
        $this->call(PlantAtHomeCategoryBulkSeeder::class);  // 193 real plant categories
        $this->call(PlantAtHomePlantBulkSeeder::class);     // 1,530 real plants + attributes

        // Tier 3 — staging only (demo products; skipped if real plants are already seeded)
        if (app()->environment('staging')) {
            $this->call(PlantAtHomeProductSeeder::class);
        } else {
            $this->command->warn('[Tier 3] Skipped demo products — not staging env.');
        }
    }
}
