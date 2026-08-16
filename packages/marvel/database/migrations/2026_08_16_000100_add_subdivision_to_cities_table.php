<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Services\LocationNormalizer;

/**
 * Teach `cities` the difference between a city and an administrative slice of one.
 *
 * The table currently holds eleven Delhi rows: Delhi itself plus its ten NCT districts, each a
 * peer of the real city. Nothing stops a customer, a vendor or a geocoder landing on "South
 * Delhi" and being treated as if it were somewhere else entirely.
 *
 * Rows are DEMOTED IN PLACE, never deleted: `postal_codes.city_id`, `carts.shopping_city_id`,
 * `city_vertical_service_settings.city_id`, `warehouses.city_id` and the CMS `city_uuid` all
 * still point here, and a delete would have to chase every one of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cities')) {
            return;
        }

        Schema::table('cities', function (Blueprint $table) {
            if (!Schema::hasColumn('cities', 'parent_city_id')) {
                $table->unsignedBigInteger('parent_city_id')->nullable()->after('state_id')->index();
            }
            if (!Schema::hasColumn('cities', 'is_subdivision')) {
                $table->boolean('is_subdivision')->default(false)->after('parent_city_id')->index();
            }
        });

        // The geo master really contains "North East  Delhi" with two spaces. Every lookup in the
        // app normalises whitespace, so those rows were unmatchable — and, ironically, that is the
        // only reason two of them ended up pointing at the RIGHT city (the bad name match failed
        // and fell back to Delhi). Fix the data so the match is correct on purpose, not by luck.
        foreach (DB::table('cities')->select('id', 'name')->get() as $row) {
            $clean = trim(preg_replace('/\s+/', ' ', (string) $row->name));
            if ($clean !== '' && $clean !== $row->name) {
                DB::table('cities')->where('id', $row->id)->update(['name' => $clean]);
            }
        }

        // Subdivision detection, per state, using the SAME pure function the runtime normalizer
        // uses — the rule cannot drift between the backfill and the service that depends on it.
        $hasDistricts = Schema::hasTable('districts');
        foreach (DB::table('cities')->select('state_id')->distinct()->pluck('state_id') as $stateId) {
            $rows = DB::table('cities')
                ->where('state_id', $stateId)
                ->get(['id', 'name', 'is_serviceable'])
                ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name, 'is_serviceable' => (bool) $r->is_serviceable])
                ->all();

            foreach (LocationNormalizer::resolveSubdivisions($rows) as $childId => $parentId) {
                $update = ['parent_city_id' => $parentId, 'is_subdivision' => true];

                // A subdivision IS a district — link it to the districts master so the normalizer
                // can hand back a district_id, not just a name. Five of the Delhi rows have a NULL
                // district_id today.
                if ($hasDistricts && Schema::hasColumn('cities', 'district_id')) {
                    $city = DB::table('cities')->where('id', $childId)->first(['name', 'state_id', 'district_id']);
                    if ($city && $city->district_id === null) {
                        $districtId = DB::table('districts')
                            ->where('state_id', $city->state_id)
                            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim((string) $city->name))])
                            ->value('id');
                        if ($districtId) {
                            $update['district_id'] = $districtId;
                        }
                    }
                }

                DB::table('cities')->where('id', $childId)->update($update);
            }
        }

        // A slice of a city is never itself a shopping destination.
        DB::table('cities')->where('is_subdivision', true)->update(['is_serviceable' => false]);

        // The normalizer memoizes the cities table per process; the next migration in this same
        // run depends on what we just wrote.
        LocationNormalizer::flush();
    }

    public function down(): void
    {
        if (!Schema::hasTable('cities')) {
            return;
        }

        Schema::table('cities', function (Blueprint $table) {
            foreach (['is_subdivision', 'parent_city_id'] as $col) {
                if (Schema::hasColumn('cities', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        // Name whitespace and back-filled district_ids are corrections, not state — they stay.
    }
};
