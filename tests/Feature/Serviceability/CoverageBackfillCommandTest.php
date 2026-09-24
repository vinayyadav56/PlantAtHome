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

    public function test_the_manual_rows_delivery_promise_moves_onto_the_rule(): void
    {
        DB::table('vendor_service_areas')->insert([
            'shop_id' => 1, 'city' => 'Gurugram', 'pincode' => null,
            'fulfillment_mode' => 'courier', 'eta_days' => 4, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('plantathome:coverage-backfill', ['--shop' => 1])->assertExitCode(0);

        $rule = DB::table('vendor_coverage_rules')
            ->where('target_key', 'city:' . $this->geo['gurugram_c'])->first();
        $this->assertSame('courier', $rule->fulfillment_mode);
        $this->assertSame(4, (int) $rule->eta_days);

        // ...and down onto the projection, which is what the resolver reads.
        $this->assertSame('courier', DB::table('vendor_covered_pincodes')
            ->where('shop_id', 1)->where('pincode', '122001')->value('fulfillment_mode'));
    }

    public function test_a_second_run_fills_a_promise_an_existing_rule_is_missing(): void
    {
        // A rule written before rules could carry a promise: re-running the
        // backfill used to skip it entirely, so retiring the manual row left
        // the vendor as 'both' with no ETA.
        DB::table('vendor_coverage_rules')->insert([
            'shop_id' => 1, 'rule_type' => 'city', 'vertical' => '*',
            'city_id' => $this->geo['gurugram_c'],
            'target_key' => 'city:' . $this->geo['gurugram_c'], 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->manualArea(1, 'Gurugram'); // local / 2

        $this->artisan('plantathome:coverage-backfill', ['--shop' => 1])
            ->expectsOutputToContain('1 adopting a mode/ETA')
            ->assertExitCode(0);

        $rule = DB::table('vendor_coverage_rules')->where('shop_id', 1)->first();
        $this->assertSame(['local', 2], [$rule->fulfillment_mode, (int) $rule->eta_days]);
        $this->assertSame(1, DB::table('vendor_coverage_rules')->count(), 'no duplicate rule');
    }

    public function test_an_explicit_promise_on_the_rule_is_never_overwritten(): void
    {
        DB::table('vendor_coverage_rules')->insert([
            'shop_id' => 1, 'rule_type' => 'city', 'vertical' => '*',
            'city_id' => $this->geo['gurugram_c'],
            'target_key' => 'city:' . $this->geo['gurugram_c'], 'is_active' => true,
            'fulfillment_mode' => 'courier', 'eta_days' => 7,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->manualArea(1, 'Gurugram'); // local / 2 — the stale side

        $this->artisan('plantathome:coverage-backfill', ['--shop' => 1])->assertExitCode(0);

        $rule = DB::table('vendor_coverage_rules')->where('shop_id', 1)->first();
        $this->assertSame(['courier', 7], [$rule->fulfillment_mode, (int) $rule->eta_days]);
    }

    public function test_retire_manual_leaves_rules_as_the_only_writer(): void
    {
        $this->manualArea(1, 'Gurugram');

        $this->artisan('plantathome:coverage-backfill', ['--shop' => 1, '--retire-manual' => true])
            ->expectsOutputToContain('INVARIANT PASS')
            ->expectsOutputToContain("retired 1 manual row(s)")
            ->assertExitCode(0);

        $manual = DB::table('vendor_service_areas')->where('shop_id', 1)->where('source', 'migrated')->first();
        $this->assertNotNull($manual);
        $this->assertFalse((bool) $manual->is_active);

        // The city is still served — by the bridge row, from the rule.
        $bridge = DB::table('vendor_service_areas')
            ->where('shop_id', 1)->where('source', 'coverage_sync')->where('city', 'Gurugram')->first();
        $this->assertNotNull($bridge);
        $this->assertTrue((bool) $bridge->is_active);
        $this->assertSame('local', $bridge->fulfillment_mode, 'the promise survived the retirement');
        $this->assertSame(2, (int) $bridge->eta_days);
    }

    public function test_retire_manual_never_fires_for_a_shop_that_failed_the_invariant(): void
    {
        // A city the master does not know: the rule cannot be derived, so the
        // manual row is the ONLY thing still serving it and must survive.
        $this->manualArea(1, 'Atlantis');

        $this->artisan('plantathome:coverage-backfill', ['--shop' => 1, '--retire-manual' => true])
            ->expectsOutputToContain('INVARIANT FAIL')
            ->assertExitCode(1);

        $row = DB::table('vendor_service_areas')->where('shop_id', 1)->where('city', 'Atlantis')->first();
        $this->assertNull($row->source, 'a failed shop keeps its manual rows');
        $this->assertTrue((bool) $row->is_active);
    }
}
