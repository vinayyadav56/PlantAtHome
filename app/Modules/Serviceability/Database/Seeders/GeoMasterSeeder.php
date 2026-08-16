<?php

namespace App\Modules\Serviceability\Database\Seeders;

use App\Modules\Serviceability\Application\PostalCodeImporter;
use App\Modules\Serviceability\Infrastructure\Models\Country;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seeds the India geo master for Delivery Coverage: the country row, the
 * states→country rollup, all districts, the full pincode master (via the same
 * PostalCodeImporter the artisan command uses), then a best-effort rollup of
 * legacy cities to districts + postal codes to cities by name match.
 * Idempotent — safe to re-run on every deploy. Standalone (DatabaseSeeder does
 * not chain module seeders): php artisan db:seed --class="App\\Modules\\Serviceability\\Database\\Seeders\\GeoMasterSeeder"
 */
class GeoMasterSeeder extends Seeder
{
    /** Overridable in tests to seed from a small fixture CSV. */
    public ?string $postalCsvPath = null;

    /**
     * @return array{states_mapped:int, districts:int, pincodes:int, cities_matched:int, cities_unmatched:int}
     */
    public function run(): array
    {
        $importer = new PostalCodeImporter(DB::connection());

        // 1. Country + states rollup (the whole legacy states table is India).
        $india = Country::firstOrCreate(
            ['iso2' => 'IN'],
            ['name' => 'India', 'iso3' => 'IND', 'phone_code' => '+91', 'is_active' => true],
        );
        DB::table('states')->update(['country_id' => $india->id]);
        $statesMapped = (int) DB::table('states')->where('country_id', $india->id)->count();

        // 2. Districts from the bundled master list (aborts on unmapped states).
        $now = now();
        $handle = fopen(__DIR__.'/../data/india_districts.csv', 'rb');
        $header = null;
        while (($fields = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($header === null) {
                $header = $fields;
                continue;
            }
            [$state, $district] = [trim((string) $fields[0]), trim((string) $fields[1])];
            if ($district === '') {
                continue;
            }
            $stateId = $importer->resolveStateId($state);
            DB::table('districts')->updateOrInsert(
                ['state_id' => $stateId, 'name' => $district],
                ['is_active' => true, 'updated_at' => $now, 'created_at' => $now],
            );
        }
        fclose($handle);

        // 3. Pincode master — same code path as plantathome:pincodes-import.
        $import = $importer->import($this->postalCsvPath);

        // 4. Best-effort rollups by name match (districts-as-cities pollution
        //    makes cities.name = districts.name the right cheap heuristic).
        [$citiesMatched, $citiesUnmatched] = $this->rollupCities();

        $report = [
            'states_mapped'     => $statesMapped,
            'districts'         => (int) DB::table('districts')->count(),
            'pincodes'          => $import['unique_pincodes'],
            'cities_matched'    => $citiesMatched,
            'cities_unmatched'  => $citiesUnmatched,
        ];
        Log::info('GeoMasterSeeder complete', $report + ['import' => $import]);
        if ($this->command) {
            $this->command->info('GeoMasterSeeder: '.json_encode($report));
        }

        return $report;
    }

    /**
     * cities.district_id where lower(city name) = lower(district name) in the
     * same state; then postal_codes.city_id for that district's pins, plus an
     * office-name contains match for the rest.
     *
     * @return array{0:int, 1:int} [matched, unmatched]
     */
    /**
     * The storefront's canonical city aliases. This used to be a hand-copied constant that had
     * already drifted — it carried six entries while the real table carried sixteen, missing every
     * Delhi NCT district, which is exactly the set that matters here. Read the one map instead.
     *
     * @return array<string,string> raw spelling => canonical key
     */
    private function cityAliases(): array
    {
        return (new \Marvel\Services\AvailabilityService())->cityAliases();
    }

    /**
     * Flag city rows that are really administrative slices of a bigger city, using the same pure
     * predicate the runtime normalizer uses. Migrations cannot do this on a fresh install — they
     * run before the city rows exist — so the seeder has to stamp them itself.
     */
    private function stampSubdivisions(): void
    {
        if (! DB::getSchemaBuilder()->hasColumn('cities', 'parent_city_id')) {
            return;
        }

        foreach (DB::table('cities')->select('state_id')->distinct()->pluck('state_id') as $stateId) {
            $rows = DB::table('cities')
                ->where('state_id', $stateId)
                ->get(['id', 'name', 'is_serviceable'])
                ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name, 'is_serviceable' => (bool) $r->is_serviceable])
                ->all();

            foreach (\Marvel\Services\LocationNormalizer::resolveSubdivisions($rows) as $childId => $parentId) {
                DB::table('cities')->where('id', $childId)->update([
                    'parent_city_id' => $parentId,
                    'is_subdivision' => true,
                    'is_serviceable' => false,
                ]);
            }
        }

        \Marvel\Services\LocationNormalizer::flush();
    }

    private function rollupCities(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('cities')) {
            return [0, 0];
        }

        $this->stampSubdivisions();

        foreach (DB::table('districts')->get(['id', 'state_id', 'name']) as $district) {
            $districtKey = mb_strtolower(trim($district->name));
            $names = [$districtKey];
            // Alias keys whose canonical form is this district also match.
            foreach ($this->cityAliases() as $alias => $canonical) {
                if ($canonical === $districtKey) {
                    $names[] = $alias;
                }
            }
            DB::table('cities')
                ->where('state_id', $district->state_id)
                ->whereIn(DB::raw('LOWER(TRIM(name))'), $names)
                ->update(['district_id' => $district->id]);
        }

        $hasParent = DB::getSchemaBuilder()->hasColumn('cities', 'parent_city_id');
        $cols = $hasParent ? ['id', 'district_id', 'parent_city_id'] : ['id', 'district_id'];
        $matchedCities = DB::table('cities')->whereNotNull('district_id')->get($cols);
        foreach ($matchedCities as $city) {
            // A subdivision keeps its district_id — it IS that district — but it must never own
            // the pins: those belong to the parent city. Binding them here by name is what made
            // half of Delhi's pincodes resolve to "South Delhi" while the other half correctly
            // resolved to "Delhi", since the metro fallback below only fills pins still unclaimed.
            $target = ($hasParent ? ($city->parent_city_id ?: $city->id) : $city->id);
            DB::table('postal_codes')->where('district_id', $city->district_id)->update(['city_id' => $target]);
        }

        // Metro city-states (Delhi, Chandigarh…): a city named exactly like its
        // STATE spans every district of that state ("North Delhi" etc.), so no
        // single district matches. Map all the state's pins to that city.
        $states = DB::table('states')->get(['id', 'name']);
        foreach ($states as $state) {
            $stateKey = mb_strtolower(trim($state->name));
            $cityStates = DB::table('cities')
                ->where('state_id', $state->id)
                ->whereNull('district_id')
                ->whereIn(DB::raw('LOWER(TRIM(name))'), array_merge(
                    [$stateKey],
                    array_keys(array_filter($this->cityAliases(), fn ($c) => $c === $stateKey)),
                ))
                ->get(['id']);
            foreach ($cityStates as $cityState) {
                DB::table('postal_codes')
                    ->where('state_id', $state->id)
                    ->whereNull('city_id')
                    ->update(['city_id' => $cityState->id]);
            }
        }

        // Remaining pins: a city name appearing inside the delivery office name.
        $unrolled = DB::table('cities')->whereNull('district_id')->whereNotNull('state_id')->get(['id', 'state_id', 'name']);
        foreach ($unrolled as $city) {
            $name = mb_strtolower(trim((string) $city->name));
            if ($name === '') {
                continue;
            }
            DB::table('postal_codes')
                ->where('state_id', $city->state_id)
                ->whereNull('city_id')
                ->whereRaw('LOWER(office_name) LIKE ?', ['%'.$name.'%'])
                ->update(['city_id' => $city->id]);
        }

        return [
            (int) DB::table('cities')->whereNotNull('district_id')->count(),
            (int) DB::table('cities')->whereNull('district_id')->count(),
        ];
    }
}
