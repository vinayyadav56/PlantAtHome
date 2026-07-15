<?php

namespace Tests\Feature\Serviceability;

use Illuminate\Support\Facades\DB;

/**
 * Shared geo fixture for Delivery Coverage tests: India, two states (Haryana,
 * Rajasthan), three districts, three cities and seven pincodes (one inactive),
 * plus two legacy vendors (shop 1 active, shop 2 inactive).
 *
 * Pins: 122001 (HR/Gurgaon/Gurugram), 122002 (HR/Gurgaon/—),
 *       121001 (HR/Faridabad/Faridabad), 121002 (HR/Faridabad/—),
 *       302001 (RJ/Jaipur/Jaipur), 302002 (RJ/Jaipur/—),
 *       122099 (HR/Gurgaon/Gurugram, status=inactive).
 */
trait SeedsCoverageGeo
{
    /** @var array<string,int> */
    protected array $geo = [];

    protected function seedCoverageGeo(): void
    {
        $now = now();
        $this->geo['country'] = (int) DB::table('countries')->insertGetId([
            'name' => 'India', 'iso2' => 'IN', 'iso3' => 'IND', 'phone_code' => '+91',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $state = fn (string $name) => (int) DB::table('states')->insertGetId([
            'name' => $name, 'country_id' => $this->geo['country'], 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->geo['haryana'] = $state('Haryana');
        $this->geo['rajasthan'] = $state('Rajasthan');

        $district = fn (string $name, int $stateId) => (int) DB::table('districts')->insertGetId([
            'state_id' => $stateId, 'name' => $name, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->geo['gurgaon'] = $district('Gurgaon', $this->geo['haryana']);
        $this->geo['faridabad_d'] = $district('Faridabad', $this->geo['haryana']);
        $this->geo['jaipur_d'] = $district('Jaipur', $this->geo['rajasthan']);

        $city = fn (string $name, int $stateId, int $districtId) => (int) DB::table('cities')->insertGetId([
            'name' => $name, 'state_id' => $stateId, 'district_id' => $districtId,
            'status' => 'active', 'is_serviceable' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->geo['gurugram_c'] = $city('Gurugram', $this->geo['haryana'], $this->geo['gurgaon']);
        $this->geo['faridabad_c'] = $city('Faridabad', $this->geo['haryana'], $this->geo['faridabad_d']);
        $this->geo['jaipur_c'] = $city('Jaipur', $this->geo['rajasthan'], $this->geo['jaipur_d']);

        $pin = fn (string $pincode, int $stateId, int $districtId, ?int $cityId, string $status = 'active') => DB::table('postal_codes')->insert([
            'country_id' => $this->geo['country'], 'state_id' => $stateId, 'district_id' => $districtId,
            'city_id' => $cityId, 'pincode' => $pincode, 'status' => $status,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $pin('122001', $this->geo['haryana'], $this->geo['gurgaon'], $this->geo['gurugram_c']);
        $pin('122002', $this->geo['haryana'], $this->geo['gurgaon'], null);
        $pin('121001', $this->geo['haryana'], $this->geo['faridabad_d'], $this->geo['faridabad_c']);
        $pin('121002', $this->geo['haryana'], $this->geo['faridabad_d'], null);
        $pin('302001', $this->geo['rajasthan'], $this->geo['jaipur_d'], $this->geo['jaipur_c']);
        $pin('302002', $this->geo['rajasthan'], $this->geo['jaipur_d'], null);
        $pin('122099', $this->geo['haryana'], $this->geo['gurgaon'], $this->geo['gurugram_c'], 'inactive');

        DB::table('shops')->insert([
            ['id' => 1, 'name' => 'Green Roots', 'slug' => 'green-roots', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Dormant Nursery', 'slug' => 'dormant', 'is_active' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
