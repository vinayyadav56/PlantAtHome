<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make pincode -> city authoritative by pointing it at cities only, never at their subdivisions.
 *
 * The master contradicts itself today. Both of these are live:
 *
 *   South West Delhi (27 pins) -> city_id 290  "Delhi"        correct
 *   South Delhi      (16 pins) -> city_id 297  "South Delhi"  wrong
 *
 * The seeder resolved a district to a city BY NAME, so wherever a same-named city row existed it
 * bound to that instead of the parent. The two districts that came out right are precisely the
 * ones whose city rows carry the double-space typo — the name match failed and fell back to
 * Delhi. Pincode is the most trustworthy signal we have about where someone lives, so it has to
 * stop disagreeing with itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('postal_codes') || !Schema::hasColumn('cities', 'parent_city_id')) {
            return;
        }

        $parents = DB::table('cities')
            ->where('is_subdivision', true)
            ->whereNotNull('parent_city_id')
            ->pluck('parent_city_id', 'id');

        foreach ($parents as $subdivisionId => $parentId) {
            DB::table('postal_codes')
                ->where('city_id', $subdivisionId)
                ->update(['city_id' => $parentId]);
        }
    }

    public function down(): void
    {
        // Not reversible by design: the previous values were wrong, and the correct district is
        // still recorded on every row in `district_id`. Re-running the geo seeder rebuilds them.
    }
};
