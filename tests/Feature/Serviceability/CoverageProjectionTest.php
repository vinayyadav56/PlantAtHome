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
}
