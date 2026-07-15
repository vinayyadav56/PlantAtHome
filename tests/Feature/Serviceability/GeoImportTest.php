<?php

namespace Tests\Feature\Serviceability;

use Illuminate\Support\Facades\DB;

/**
 * Pincode-master import (plantathome:pincodes-import / PostalCodeImporter):
 * counts, dominant-district rows, idempotent re-runs, state-name
 * normalization, the unmapped-state abort, and the public geo endpoints.
 */
class GeoImportTest extends ServiceabilityTestCase
{
    private const FIXTURE = 'tests/Feature/Serviceability/fixtures/postal_codes_sample.csv';

    private int $delhiId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->delhiId = (int) DB::table('states')->insertGetId([
            'name' => 'Delhi', 'code' => 'DL', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function importFixture(): void
    {
        $this->artisan('plantathome:pincodes-import', ['path' => base_path(self::FIXTURE)])
            ->assertExitCode(0);
    }

    public function test_import_counts_dominant_district_and_idempotent_rerun(): void
    {
        $this->importFixture();

        // Fixture: 50 rows, 40 unique pins (110001–110010 duplicated), 7 districts.
        $this->assertSame(40, DB::table('postal_codes')->count());
        $this->assertSame(7, DB::table('districts')->count());
        $this->assertSame(1, DB::table('countries')->where('iso2', 'IN')->count());

        // Dominant district honored (fixture pre-aggregates to 1 row/pin).
        $row = DB::table('postal_codes')->where('pincode', '110001')->first();
        $this->assertSame($this->delhiId, (int) $row->state_id);
        $this->assertSame('Central Delhi', DB::table('districts')->where('id', $row->district_id)->value('name'));
        $this->assertSame('Parliament House', $row->office_name);
        $this->assertIsArray(json_decode((string) $row->offices, true));

        // Idempotent: re-running upserts, never duplicates.
        $this->importFixture();
        $this->assertSame(40, DB::table('postal_codes')->count());
        $this->assertSame(7, DB::table('districts')->count());
    }

    public function test_state_names_normalize_through_the_map(): void
    {
        // 'NCT of Delhi' is a dataset alias -> canonical states.name 'Delhi'.
        $csv = tempnam(sys_get_temp_dir(), 'pah_pins').'.csv';
        file_put_contents($csv, implode("\n", [
            'pincode,state,district,office_name,latitude,longitude,offices',
            '110099,NCT of Delhi,Central Delhi,Test Office,28.6,77.2,',
        ]));

        $this->artisan('plantathome:pincodes-import', ['path' => $csv])->assertExitCode(0);

        $this->assertSame(
            $this->delhiId,
            (int) DB::table('postal_codes')->where('pincode', '110099')->value('state_id'),
        );
        @unlink($csv);
    }

    public function test_unmapped_state_aborts_and_rolls_back(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'pah_pins').'.csv';
        file_put_contents($csv, implode("\n", [
            'pincode,state,district,office_name,latitude,longitude,offices',
            '110001,Delhi,Central Delhi,Parliament House,28.6,77.2,',
            '999001,Atlantis,Lost District,Nowhere,0,0,',
        ]));

        $this->artisan('plantathome:pincodes-import', ['path' => $csv])->assertExitCode(1);

        // The whole file rolled back — even the valid first row.
        $this->assertSame(0, DB::table('postal_codes')->count());
        @unlink($csv);
    }

    public function test_public_geo_endpoints_serve_the_imported_master(): void
    {
        $this->importFixture();

        $states = $this->getJson('/api/v1/serviceability/geo/states')->assertStatus(200)->json('data');
        $this->assertContains('Delhi', array_column($states, 'name'));

        $this->getJson('/api/v1/serviceability/geo/districts')->assertStatus(422); // state_id required

        $districts = $this->getJson("/api/v1/serviceability/geo/districts?state_id={$this->delhiId}")
            ->assertStatus(200)->assertJsonPath('success', true)->json('data');
        $this->assertCount(7, $districts);

        $central = collect($districts)->firstWhere('name', 'Central Delhi');
        $pins = $this->getJson("/api/v1/serviceability/geo/postal-codes?district_id={$central['id']}")
            ->assertStatus(200)->json();
        $this->assertTrue($pins['success']);
        $this->assertSame(7, $pins['meta']['pagination']['total']); // unique Central Delhi pins
        $this->assertSame('110001', $pins['data'][0]['pincode']);
    }
}
