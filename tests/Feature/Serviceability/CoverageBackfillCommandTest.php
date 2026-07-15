<?php

namespace Tests\Feature\Serviceability;

use Illuminate\Support\Facades\DB;

/**
 * plantathome:coverage-backfill — legacy manual vendor_service_areas rows →
 * coverage rules (city rows → city rules, pincode rows → include rules), one
 * sync per shop, idempotent re-runs, and the superset invariant: every
 * original manual city must reappear in the derived coverage_sync bridge rows.
 */
class CoverageBackfillCommandTest extends ServiceabilityTestCase
{
    use SeedsCoverageGeo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCoverageGeo();
    }

    private function manualArea(int $shopId, string $city, ?string $pincode = null): void
    {
        DB::table('vendor_service_areas')->insert([
            'shop_id' => $shopId, 'city' => $city, 'pincode' => $pincode,
            'fulfillment_mode' => 'local', 'eta_days' => 2, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_dry_run_plans_rules_without_writing(): void
    {
        $this->manualArea(1, 'Gurugram');
        $this->manualArea(1, 'Jaipur', '302001');

        $this->artisan('plantathome:coverage-backfill', ['--shop' => 1, '--dry-run' => true])
            ->expectsOutputToContain('city:' . $this->geo['gurugram_c'])
            ->expectsOutputToContain('pincode_include:302001')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('vendor_coverage_rules')->count());
        $this->assertSame(0, DB::table('vendor_covered_pincodes')->count());
    }

    public function test_backfill_derives_rules_syncs_and_passes_the_superset_invariant(): void
    {
        $this->manualArea(1, 'Gurugram');            // city rule (all Gurugram pins)
        $this->manualArea(1, 'Faridabad');           // city rule
        $this->manualArea(1, 'Jaipur', '302001');    // pincode row → include rule

        $this->artisan('plantathome:coverage-backfill', ['--shop' => 1])
            ->expectsOutputToContain('INVARIANT PASS')
            ->assertExitCode(0);

        // Rules: three city rules + one include — a row carrying BOTH a city
        // and a pincode contributes BOTH (dropping the city half shrank
        // coverage and failed the invariant on staging).
        $expected = collect([
            'city:' . $this->geo['faridabad_c'],
            'city:' . $this->geo['gurugram_c'],
            'city:' . $this->geo['jaipur_c'],
            'pincode_include:302001',
        ])->sort()->values()->all();
        $this->assertSame(
            $expected,
            DB::table('vendor_coverage_rules')->where('shop_id', 1)->orderBy('target_key')->pluck('target_key')->all()
        );
        // Projection: 122001 (Gurugram), 121001 (Faridabad), 302001 (manual).
        $this->assertSame(
            ['121001', '122001', '302001'],
            DB::table('vendor_covered_pincodes')->where('shop_id', 1)->orderBy('pincode')->pluck('pincode')->all()
        );
        // Bridge: every manual city re-derived (superset invariant), manual rows intact.
        $bridge = DB::table('vendor_service_areas')->where('shop_id', 1)->where('source', 'coverage_sync')
            ->pluck('city')->map(fn ($c) => strtolower($c));
        foreach (['gurugram', 'faridabad', 'jaipur'] as $city) {
            $this->assertTrue($bridge->contains($city), "bridge missing {$city}");
        }
        $this->assertSame(3, DB::table('vendor_service_areas')->where('shop_id', 1)->whereNull('source')->count());
        // Bridge rows inherit the manual rows' fulfillment metadata for the same city.
        $this->assertSame(2, (int) DB::table('vendor_service_areas')
            ->where('shop_id', 1)->where('source', 'coverage_sync')
            ->whereRaw('LOWER(city) = ?', ['gurugram'])->value('eta_days'));

        $this->assertSame(1, DB::table('coverage_audit_logs')->where('shop_id', 1)->where('action', 'backfill')->count());

        // Idempotent: a re-run creates nothing new.
        $this->artisan('plantathome:coverage-backfill', ['--shop' => 1])->assertExitCode(0);
        $this->assertSame(4, DB::table('vendor_coverage_rules')->where('shop_id', 1)->count());
    }

    public function test_unresolvable_city_fails_the_invariant_and_the_command(): void
    {
        $this->manualArea(2, 'Gurugram');
        $this->manualArea(2, 'Atlantis'); // not in the cities master

        $this->artisan('plantathome:coverage-backfill', ['--shop' => 2])
            ->expectsOutputToContain('INVARIANT FAIL')
            ->expectsOutputToContain('Atlantis')
            ->assertExitCode(1);

        // The resolvable city still backfilled (partial progress, not all-or-nothing).
        $this->assertSame(
            ['city:' . $this->geo['gurugram_c']],
            DB::table('vendor_coverage_rules')->where('shop_id', 2)->pluck('target_key')->all()
        );
    }
}
