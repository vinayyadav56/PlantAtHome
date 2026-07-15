<?php

namespace Tests\Feature\Serviceability;

use App\Modules\Serviceability\Application\DeliveryCoverageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The coverage-aware public pincode check (marvel GET /api/delivery-pincodes/check):
 * legacy allow-list OR-ed with the Delivery Coverage projection, dual fail-open
 * only when NEITHER is configured, divergence audit rows only when the two
 * configured systems disagree, and the City Activation override still winning.
 */
class DeliveryCheckCoverageTest extends ServiceabilityTestCase
{
    use SeedsCoverageGeo;

    private DeliveryCoverageService $coverage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCoverageGeo();
        $this->coverage = $this->app->make(DeliveryCoverageService::class);

        // Legacy allow-list table (marvel migration replica — columns the
        // DeliveryPincode model reads).
        Schema::create('delivery_pincodes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('pincode', 12)->unique();
            $t->string('pincode_end', 12)->nullable();
            $t->string('area')->nullable();
            $t->string('city')->nullable();
            $t->string('state')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('cod_enabled')->default(true);
            $t->unsignedSmallInteger('eta_days')->nullable();
            $t->timestamps();
        });
    }

    private function allowRow(string $pincode, array $attrs = []): void
    {
        DB::table('delivery_pincodes')->insert($attrs + [
            'pincode' => $pincode, 'area' => 'Sector 1', 'city' => 'Gurugram', 'state' => 'Haryana',
            'is_active' => true, 'cod_enabled' => true, 'eta_days' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function divergenceCount(?string $pincode = null): int
    {
        return DB::table('coverage_audit_logs')->where('action', 'divergence')
            ->when($pincode !== null, fn ($q) => $q->where('payload', 'like', '%"pincode":"' . $pincode . '"%'))
            ->count();
    }

    public function test_dual_fail_open_when_neither_system_is_configured(): void
    {
        $res = $this->getJson('/api/delivery-pincodes/check?pincode=122001');

        $res->assertStatus(200)->assertJson([
            'serviceable'       => true,
            'unconfigured'      => true,
            'available_vendors' => 0,
            'source'            => null,
        ]);
        $this->assertSame(0, $this->divergenceCount());
    }

    public function test_allowlist_only_hit_reports_allowlist_source_and_no_divergence(): void
    {
        $this->allowRow('122001');

        $res = $this->getJson('/api/delivery-pincodes/check?pincode=122001');

        $res->assertStatus(200)->assertJson([
            'serviceable'       => true,
            'pincode'           => '122001',
            'city'              => 'Gurugram',
            'state'             => 'Haryana',
            'eta_days'          => 2,
            'available_vendors' => 0,
            'source'            => 'allowlist',
        ]);
        // Coverage side unconfigured — a one-sided miss is NOT a divergence.
        $this->assertSame(0, $this->divergenceCount());
    }

    public function test_coverage_only_hit_enriches_from_postal_master(): void
    {
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);

        $res = $this->getJson('/api/delivery-pincodes/check?pincode=122001');

        $res->assertStatus(200)->assertJson([
            'serviceable'       => true,
            'pincode'           => '122001',
            'area'              => null,
            'city'              => 'Gurugram', // from postal_codes → cities
            'state'             => 'Haryana',  // from postal_codes → states
            'eta_days'          => null,       // no allow-list row to inherit from
            'available_vendors' => 1,
            'source'            => 'coverage',
        ]);
        // Allow-list unconfigured — no divergence may be logged.
        $this->assertSame(0, $this->divergenceCount());

        // A pin the vendor does NOT cover is a plain miss (not unconfigured).
        $miss = $this->getJson('/api/delivery-pincodes/check?pincode=302001');
        $miss->assertStatus(200)->assertJson(['serviceable' => false, 'available_vendors' => 0, 'source' => null]);
        $miss->assertJsonMissing(['unconfigured' => true]);
    }

    public function test_divergence_logged_only_on_disagreement_and_throttled(): void
    {
        // Both systems configured: coverage covers Gurgaon district; the
        // allow-list only knows 121001.
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);
        $this->allowRow('121001', ['city' => 'Faridabad']);

        // Agreement (both say yes for 121001? coverage says NO for 121001) —
        // first prove a disagreement IS logged: coverage yes / allow-list no.
        $res = $this->getJson('/api/delivery-pincodes/check?pincode=122001');
        $res->assertStatus(200)->assertJson(['serviceable' => true, 'source' => 'coverage', 'available_vendors' => 1]);
        $this->assertSame(1, $this->divergenceCount('122001'));

        $payload = json_decode((string) DB::table('coverage_audit_logs')->where('action', 'divergence')->value('payload'), true);
        $this->assertSame(['pincode' => '122001', 'coverage' => true, 'allowlist' => false], $payload);
        $this->assertSame(0, (int) DB::table('coverage_audit_logs')->where('action', 'divergence')->value('shop_id'));

        // Throttled: a second check within the hour must not add another row.
        $this->getJson('/api/delivery-pincodes/check?pincode=122001')->assertStatus(200);
        $this->assertSame(1, $this->divergenceCount('122001'));

        // Agreement case: make coverage also cover 121001 → both yes → no new divergence.
        $this->coverage->addCoverage(1, 'city', ['city_id' => $this->geo['faridabad_c']]);
        $agree = $this->getJson('/api/delivery-pincodes/check?pincode=121001');
        $agree->assertStatus(200)->assertJson(['serviceable' => true, 'source' => 'both', 'available_vendors' => 1]);
        $this->assertSame(0, $this->divergenceCount('121001'));
    }

    public function test_city_paused_override_still_wins_over_a_coverage_hit(): void
    {
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);
        DB::table('cities')->where('id', $this->geo['gurugram_c'])->update(['status' => 'paused']);

        $res = $this->getJson('/api/delivery-pincodes/check?pincode=122001');

        $res->assertStatus(200)->assertJson([
            'serviceable'       => false,
            'pincode'           => '122001',
            'city'              => 'Gurugram',
            'reason'            => 'city_paused',
            'available_vendors' => 1,
            'source'            => 'coverage',
        ]);
    }

    public function test_allowlist_eta_and_area_are_kept_when_both_systems_hit(): void
    {
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);
        $this->allowRow('122001', ['eta_days' => 5, 'area' => 'DLF Phase 3']);

        $res = $this->getJson('/api/delivery-pincodes/check?pincode=122001');

        $res->assertStatus(200)->assertJson([
            'serviceable'       => true,
            'area'              => 'DLF Phase 3',
            'eta_days'          => 5,
            'available_vendors' => 1,
            'source'            => 'both',
        ]);
    }
}
