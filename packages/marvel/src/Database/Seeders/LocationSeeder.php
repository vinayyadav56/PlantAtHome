<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\City;
use Marvel\Database\Models\State;

/**
 * Master Location System (Phase 2) — seeds the canonical Indian states and the
 * serviceable cities derived from the existing delivery_pincodes allow-list (the
 * curated city/state set). Idempotent + additive (firstOrCreate), safe to re-run
 * on every deploy — never overwrites an admin's city status/settings.
 */
class LocationSeeder extends Seeder
{
    /** Indian states + UTs with ISO-style codes. */
    private const STATES = [
        'Andhra Pradesh' => 'AP', 'Arunachal Pradesh' => 'AR', 'Assam' => 'AS',
        'Bihar' => 'BR', 'Chhattisgarh' => 'CG', 'Goa' => 'GA', 'Gujarat' => 'GJ',
        'Haryana' => 'HR', 'Himachal Pradesh' => 'HP', 'Jharkhand' => 'JH',
        'Karnataka' => 'KA', 'Kerala' => 'KL', 'Madhya Pradesh' => 'MP',
        'Maharashtra' => 'MH', 'Manipur' => 'MN', 'Meghalaya' => 'ML',
        'Mizoram' => 'MZ', 'Nagaland' => 'NL', 'Odisha' => 'OD', 'Punjab' => 'PB',
        'Rajasthan' => 'RJ', 'Sikkim' => 'SK', 'Tamil Nadu' => 'TN',
        'Telangana' => 'TG', 'Tripura' => 'TR', 'Uttar Pradesh' => 'UP',
        'Uttarakhand' => 'UK', 'West Bengal' => 'WB',
        'Andaman and Nicobar Islands' => 'AN', 'Chandigarh' => 'CH',
        'Dadra and Nagar Haveli and Daman and Diu' => 'DN', 'Delhi' => 'DL',
        'Jammu and Kashmir' => 'JK', 'Ladakh' => 'LA', 'Lakshadweep' => 'LD',
        'Puducherry' => 'PY',
    ];

    public function run(): void
    {
        // 1. States.
        foreach (self::STATES as $name => $code) {
            State::firstOrCreate(['name' => $name], ['code' => $code, 'is_active' => true]);
        }

        $statesByName = State::pluck('id', 'name');
        // Lower-cased index so 'delhi' / 'DELHI' from pincode data still match.
        $statesByLower = [];
        foreach ($statesByName as $name => $id) {
            $statesByLower[strtolower($name)] = $id;
        }

        // 2. Cities from the curated delivery_pincodes allow-list (city + state).
        if (DB::getSchemaBuilder()->hasTable('delivery_pincodes')) {
            $rows = DB::table('delivery_pincodes')
                ->select('city', 'state')
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->distinct()
                ->get();

            foreach ($rows as $row) {
                $cityName = trim($row->city);
                if ($cityName === '') {
                    continue;
                }
                $stateName = trim((string) ($row->state ?? ''));
                $stateId = $stateName !== '' ? ($statesByLower[strtolower($stateName)] ?? null) : null;

                City::firstOrCreate(
                    ['name' => $cityName, 'state_id' => $stateId],
                    [
                        'state_name'     => $stateName ?: null,
                        'status'         => City::STATUS_ACTIVE,
                        'is_serviceable' => true,
                    ]
                );
            }
        }
    }
}
