<?php

namespace Tests\Feature\Serviceability;

use App\Modules\Serviceability\Application\DeliveryCoverageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Services\CoverageBridge;

/**
 * CoverageBridge::allowedShops — the ONE narrowing the PDP badge, the PDP price
 * ETA, the checkout estimate and the checkout gate all apply.
 *
 * Each of those used to decide for itself who could deliver, from a different
 * table, which is how a product page could promise same-day local delivery that
 * the order then refused. What matters here is the fail-open contract: NULL
 * means "do not narrow", and every caller leaves its previous answer untouched.
 */
class CoverageNarrowingTest extends ServiceabilityTestCase
{
    use SeedsCoverageGeo;

    private DeliveryCoverageService $coverage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCoverageGeo();
        DB::table('shops')->where('id', 2)->update(['is_active' => true]);
        $this->coverage = $this->app->make(DeliveryCoverageService::class);

        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->json('options')->nullable();
            $t->string('language', 8)->default('en');
            $t->timestamps();
        });
        $this->setFlag(true);
    }

    private function setFlag(?bool $on): void
    {
        DB::table('settings')->delete();
        DB::table('settings')->insert([
            'options'    => json_encode($on === null ? [] : ['coverageCheckoutGate' => $on]),
            'language'   => defined('DEFAULT_LANGUAGE') ? DEFAULT_LANGUAGE : 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_it_keeps_only_the_vendors_that_cover_the_pincode(): void
    {
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);
        $this->coverage->addCoverage(2, 'district', ['district_id' => $this->geo['jaipur_d']]);

        $this->assertSame([1], array_keys(CoverageBridge::allowedShops([1, 2], '122001') ?? []));
        $this->assertSame([2], array_keys(CoverageBridge::allowedShops([1, 2], '302001') ?? []));
    }

    public function test_a_vendor_with_no_rules_is_never_narrowed_out(): void
    {
        // Per-vendor opt-in: enforcement starts when a vendor declares coverage,
        // so an unmigrated vendor keeps selling everywhere.
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);

        $allowed = CoverageBridge::allowedShops([1, 2], '302001');
        $this->assertSame([2], array_keys($allowed ?? []), 'shop 1 is configured and does not cover it; shop 2 has no rules');
    }

    public function test_opt_in_is_per_vertical_too(): void
    {
        // Shop 2 declared TOOLS coverage only. It never declared plants, so a
        // plants question must not be enforced against an empty plants scope.
        $this->coverage->addCoverage(2, 'district', ['district_id' => $this->geo['jaipur_d'], 'vertical' => 'tools']);

        $this->assertSame([2], array_keys(CoverageBridge::allowedShops([2], '122001', 'plants') ?? []));
        $this->assertSame([], array_keys(CoverageBridge::allowedShops([2], '122001', 'tools') ?? []));
        $this->assertSame([2], array_keys(CoverageBridge::allowedShops([2], '302001', 'tools') ?? []));
    }

    public function test_it_returns_null_whenever_narrowing_must_not_apply(): void
    {
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);

        $this->assertNull(CoverageBridge::allowedShops([1], null), 'no pincode');
        $this->assertNull(CoverageBridge::allowedShops([1], '1220'), 'not 6 digits');
        $this->assertNull(CoverageBridge::allowedShops([], '122001'), 'no vendors to narrow');

        $this->setFlag(false);
        $this->assertNull(CoverageBridge::allowedShops([1], '302001'), 'flag off');
    }

    public function test_an_inactive_vendor_is_narrowed_out_even_where_it_has_rules(): void
    {
        DB::table('shops')->where('id', 2)->update(['is_active' => false]);
        $this->coverage->addCoverage(2, 'district', ['district_id' => $this->geo['jaipur_d']]);

        $this->assertSame([], array_keys(CoverageBridge::allowedShops([2], '302001') ?? []));
    }
}
