<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Services\LocationNormalizer;

/**
 * Merge city rows that differ only by whitespace.
 *
 * The previous migration cleaned "North East  Delhi" down to one space. IndiaCitySeeder keys its
 * existing-row check on `strtolower(trim(name))` — trim only, no internal collapse — so on the very
 * next deploy it no longer recognised those rows and inserted the double-spaced originals again as
 * NEW cities. Three duplicate Delhi districts appeared, unflagged as subdivisions because the
 * stamping migration had already run.
 *
 * The seeder is fixed at the source (it collapses on both sides now, so it cannot re-create them).
 * This cleans up what the one bad deploy left behind, and is written as a general merge rather than
 * a hardcoded list of three ids so a re-run is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cities')) {
            return;
        }

        $groups = [];
        foreach (DB::table('cities')->get(['id', 'name', 'state_id', 'is_serviceable']) as $row) {
            $key = LocationNormalizer::key($row->name) . '|' . (int) $row->state_id;
            $groups[$key][] = $row;
        }

        foreach ($groups as $rows) {
            if (count($rows) < 2) {
                continue;
            }
            // Keep a serviceable row if there is one, else the oldest — it is the row every
            // existing FK already points at.
            usort($rows, function ($a, $b) {
                return [(int) !$a->is_serviceable, (int) $a->id] <=> [(int) !$b->is_serviceable, (int) $b->id];
            });
            $keep = array_shift($rows);

            foreach ($rows as $dupe) {
                $this->repoint((int) $dupe->id, (int) $keep->id);
                DB::table('cities')->where('id', $dupe->id)->delete();
            }

            DB::table('cities')->where('id', $keep->id)->update([
                'name' => LocationNormalizer::key($keep->name) === '' ? $keep->name : trim(preg_replace('/\s+/', ' ', (string) $keep->name)),
            ]);
        }

        // The re-inserted rows arrived after subdivisions were stamped, so anything that survived
        // the merge needs classifying again.
        LocationNormalizer::flush();
        foreach (DB::table('cities')->select('state_id')->distinct()->pluck('state_id') as $stateId) {
            $rows = DB::table('cities')->where('state_id', $stateId)->get(['id', 'name', 'is_serviceable'])
                ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name, 'is_serviceable' => (bool) $r->is_serviceable])
                ->all();
            foreach (LocationNormalizer::resolveSubdivisions($rows) as $childId => $parentId) {
                DB::table('cities')->where('id', $childId)->update([
                    'parent_city_id' => $parentId,
                    'is_subdivision' => true,
                    'is_serviceable' => false,
                ]);
            }
        }

        // A subdivision must never own pincodes — the parent city does.
        if (Schema::hasTable('postal_codes')) {
            $parents = DB::table('cities')->where('is_subdivision', true)->whereNotNull('parent_city_id')
                ->pluck('parent_city_id', 'id');
            foreach ($parents as $subId => $parentId) {
                DB::table('postal_codes')->where('city_id', $subId)->update(['city_id' => $parentId]);
            }
        }

        LocationNormalizer::flush();
    }

    public function down(): void
    {
        // Not reversible: the deleted rows were accidental duplicates of rows that still exist.
    }

    /** Move every reference off a duplicate city row and onto the one being kept. */
    private function repoint(int $from, int $to): void
    {
        $refs = [
            'postal_codes'                  => 'city_id',
            'carts'                         => 'shopping_city_id',
            'city_vertical_service_settings' => 'city_id',
            'warehouses'                    => 'city_id',
            'vendor_coverage_rules'         => 'city_id',
            'vendor_covered_pincodes'       => 'city_id',
            'cities'                        => 'parent_city_id',
        ];

        foreach ($refs as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::table($table)->where($column, $from)->update([$column => $to]);
            }
        }
    }
};
