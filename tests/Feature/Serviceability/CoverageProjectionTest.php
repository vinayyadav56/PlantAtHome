<?php

namespace Tests\Feature\Serviceability;

use App\Modules\Serviceability\Application\DeliveryCoverageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * CoverageProjector mechanics: projection rewrite counts, the cache version
 * bump, preview parity, and the legacy vendor_service_areas bridge
 * (coverage_sync rows only; manual rows inherited from + preserved).
 */
class CoverageProjectionTest extends ServiceabilityTestCase
{
    use SeedsCoverageGeo;

    private DeliveryCoverageService $coverage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCoverageGeo();
        $this->coverage = $this->app->make(DeliveryCoverageService::class);
    }

    private function projection(int $shopId)
    {
        return DB::table('vendor_covered_pincodes')->where('shop_id', $shopId);
    }

    public function test_sync_counts_match_projection_and_preview(): void
    {
        $rules = [['rule_type' => 'state', 'state_id' => $this->geo['haryana']]];

        $preview = $this->coverage->previewCoverage($rules);

        $this->coverage->addCoverage(1, 'state', ['state_id' => $this->geo['haryana']]);

        // Haryana's ACTIVE pins: 122001, 122002, 121001, 121002 (122099 inactive).
        $this->assertSame(4, $this->projection(1)->count());
        $this->assertSame($preview['total'], $this->projection(1)->count());
        $this->assertSame(['state' => 4], $preview['by_source']);
        $this->assertSame(['121001', '121002', '122001', '122002'], $preview['sample']);
    }

    public function test_rule_removal_shrinks_the_projection(): void
    {
        $stateRule = $this->coverage->addCoverage(1, 'state', ['state_id' => $this->geo['haryana']]);
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);
        $this->assertSame(4, $this->projection(1)->count());

        $this->coverage->removeCoverage(1, $stateRule->id);

        // Only district Gurgaon remains: 122001 + 122002.
        $this->assertSame(
            ['122001' => 'district', '122002' => 'district'],
            $this->projection(1)->orderBy('pincode')->pluck('source', 'pincode')->all(),
        );
    }

    public function test_bridge_writes_only_coverage_sync_rows_and_inherits_manual_settings(): void
    {
        // Pre-existing manual row (vendor typed it during onboarding).
        DB::table('vendor_service_areas')->insert([
            'shop_id' => 1, 'city' => 'Gurugram', 'pincode' => null, 'source' => null,
            'fulfillment_mode' => 'local', 'eta_days' => 3, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->coverage->addCoverage(1, 'state', ['state_id' => $this->geo['haryana']]);

        // Derived cities: Gurugram (122001) + Faridabad (121001).
        $bridge = DB::table('vendor_service_areas')->where('shop_id', 1)
            ->where('source', 'coverage_sync')->orderBy('city')->get();
        $this->assertSame(['Faridabad', 'Gurugram'], $bridge->pluck('city')->all());
        $this->assertSame(['both', 'local'], $bridge->pluck('fulfillment_mode')->all()); // default vs inherited
        $this->assertSame([null, 3], $bridge->pluck('eta_days')->map(fn ($v) => $v !== null ? (int) $v : null)->all());

        // The manual row is untouched, and nothing else was written.
        $this->assertSame(1, DB::table('vendor_service_areas')->where('shop_id', 1)->whereNull('source')->count());
        $this->assertSame(3, DB::table('vendor_service_areas')->where('shop_id', 1)->count());
    }

    public function test_stale_coverage_sync_rows_are_pruned_but_manual_rows_survive(): void
    {
        DB::table('vendor_service_areas')->insert([
            'shop_id' => 1, 'city' => 'Gurugram', 'pincode' => null, 'source' => null,
            'fulfillment_mode' => 'local', 'eta_days' => 3, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->coverage->addCoverage(1, 'state', ['state_id' => $this->geo['haryana']]);

        // Replace-all with district Faridabad only -> Gurugram no longer derived.
        $stats = $this->coverage->syncRules(1, [
            ['rule_type' => 'district', 'district_id' => $this->geo['faridabad_d']],
        ]);

        $this->assertSame(2, $stats['pincodes']); // 121001 + 121002
        $sync = DB::table('vendor_service_areas')->where('shop_id', 1)->where('source', 'coverage_sync')->get();
        $this->assertSame(['Faridabad'], $sync->pluck('city')->all());
        // Manual Gurugram row survives the prune.
        $this->assertSame(1, DB::table('vendor_service_areas')->where('shop_id', 1)->whereNull('source')->where('city', 'Gurugram')->count());
        $this->assertSame(1, DB::table('coverage_audit_logs')->where('shop_id', 1)->where('action', 'sync')->count());
    }

    public function test_every_sync_bumps_the_coverage_cache_version(): void
    {
        $before = (int) Cache::get('coverage:ver', 0);

        $this->coverage->addCoverage(1, 'city', ['city_id' => $this->geo['gurugram_c']]);
        $this->assertSame($before + 1, (int) Cache::get('coverage:ver'));

        $this->coverage->syncCoverage(1);
        $this->assertSame($before + 2, (int) Cache::get('coverage:ver'));
    }

    public function test_sync_returns_stats_shape(): void
    {
        $this->coverage->addCoverage(1, 'state', ['state_id' => $this->geo['rajasthan']]);
        $stats = $this->coverage->syncCoverage(1);

        $this->assertSame(2, $stats['pincodes']);            // 302001 + 302002
        $this->assertSame(['state' => 2], $stats['by_source']);
        $this->assertSame(1, $stats['cities']);               // Jaipur
        $this->assertSame([], $stats['unknown_pincodes']);
        $this->assertIsInt($stats['duration_ms']);
    }

    /* ── per-vertical projection ──────────────────────────────────────── */

    public function test_each_vertical_projects_its_own_rows(): void
    {
        $this->coverage->syncRules(1, [
            ['rule_type' => 'district', 'district_id' => $this->geo['gurgaon']],
            ['rule_type' => 'district', 'district_id' => $this->geo['faridabad_d'], 'vertical' => 'tools'],
        ]);

        $this->assertSame(['122001', '122002'], $this->projection(1)->where('vertical', '*')
            ->orderBy('pincode')->pluck('pincode')->all());
        $this->assertSame(['121001', '121002'], $this->projection(1)->where('vertical', 'tools')
            ->orderBy('pincode')->pluck('pincode')->all());
    }

    public function test_the_same_pincode_can_belong_to_two_verticals(): void
    {
        // The old unique key was (shop_id, pincode) — this pair would have
        // collided and the second row been dropped.
        $this->coverage->syncRules(1, [
            ['rule_type' => 'pincode_include', 'pincode' => '122001'],
            ['rule_type' => 'pincode_include', 'pincode' => '122001', 'vertical' => 'tools'],
        ]);

        $this->assertSame(2, $this->projection(1)->where('pincode', '122001')->count());
    }

    public function test_the_winning_tier_sets_the_delivery_promise(): void
    {
        $this->coverage->syncRules(1, [
            ['rule_type' => 'state', 'state_id' => $this->geo['haryana'], 'fulfillment_mode' => 'courier', 'eta_days' => 7],
            ['rule_type' => 'city', 'city_id' => $this->geo['gurugram_c'], 'fulfillment_mode' => 'local', 'eta_days' => 1],
        ]);

        $city = $this->projection(1)->where('pincode', '122001')->first();
        $this->assertSame(['city', 'local', 1], [$city->source, $city->fulfillment_mode, (int) $city->eta_days]);

        $state = $this->projection(1)->where('pincode', '121001')->first();
        $this->assertSame(['state', 'courier', 7], [$state->source, $state->fulfillment_mode, (int) $state->eta_days]);
    }

    public function test_a_district_exclude_removes_its_pins_without_hundreds_of_rules(): void
    {
        $this->coverage->syncRules(1, [
            ['rule_type' => 'state', 'state_id' => $this->geo['haryana']],
            ['rule_type' => 'district_exclude', 'district_id' => $this->geo['faridabad_d']],
        ]);

        $this->assertSame(['122001', '122002'], $this->projection(1)->orderBy('pincode')->pluck('pincode')->all());
    }

    public function test_a_city_exclude_spares_the_districts_city_less_pins(): void
    {
        $this->coverage->syncRules(1, [
            ['rule_type' => 'district', 'district_id' => $this->geo['gurgaon']],
            ['rule_type' => 'city_exclude', 'city_id' => $this->geo['gurugram_c']],
        ]);

        // 122001 belongs to Gurugram and goes; 122002 has no city and stays.
        $this->assertSame(['122002'], $this->projection(1)->pluck('pincode')->all());
    }

    /* ── the downstream refresh (previously untested) ─────────────────── */

    public function test_every_sync_dispatches_the_availability_recompute(): void
    {
        \Illuminate\Support\Facades\Bus::fake();

        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);

        \Illuminate\Support\Facades\Bus::assertDispatched(
            \Marvel\Jobs\RecomputeShopAvailabilityJob::class,
            fn ($job) => $job->shopId === 1,
        );
    }

    public function test_a_derived_city_is_switched_on_for_the_storefront_picker(): void
    {
        DB::table('cities')->where('id', $this->geo['gurugram_c'])->update(['is_serviceable' => false]);

        $this->coverage->addCoverage(1, 'city', ['city_id' => $this->geo['gurugram_c']]);

        $this->assertTrue((bool) DB::table('cities')->where('id', $this->geo['gurugram_c'])->value('is_serviceable'));
    }

    public function test_a_disabled_city_is_never_switched_back_on_by_a_coverage_rule(): void
    {
        // status=disabled is the ops kill switch; coverage must not override it.
        DB::table('cities')->where('id', $this->geo['gurugram_c'])
            ->update(['is_serviceable' => false, 'status' => 'disabled']);

        $this->coverage->addCoverage(1, 'city', ['city_id' => $this->geo['gurugram_c']]);

        $this->assertFalse((bool) DB::table('cities')->where('id', $this->geo['gurugram_c'])->value('is_serviceable'));
    }
}
