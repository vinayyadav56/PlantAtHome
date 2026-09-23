<?php

namespace Tests\Feature\Serviceability;

use App\Modules\Serviceability\Application\DeliveryCoverageService;
use App\Modules\Serviceability\Application\VendorServiceabilityResolver;
use Illuminate\Support\Facades\DB;

/**
 * The one resolver's truth table. Every gate routes through this class, so
 * what it says here is what listing, PDP, cart, verify and storeOrder say.
 */
class VendorServiceabilityResolverTest extends ServiceabilityTestCase
{
    use SeedsCoverageGeo;

    private DeliveryCoverageService $coverage;

    private VendorServiceabilityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCoverageGeo();
        $this->coverage = $this->app->make(DeliveryCoverageService::class);
        $this->resolver = $this->app->make(VendorServiceabilityResolver::class);
    }

    public function test_a_district_rule_reaches_pins_that_have_no_city(): void
    {
        // Two thirds of master cities were never rolled up under a district, so
        // district is the only selection unit that reaches every pin in it.
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);

        $this->assertTrue($this->resolver->resolve(1, '122001')['serviceable']); // has a city
        $this->assertTrue($this->resolver->resolve(1, '122002')['serviceable']); // city_id NULL
    }

    public function test_a_city_rule_covers_only_the_pins_rolled_up_to_that_city(): void
    {
        $this->coverage->addCoverage(1, 'city', ['city_id' => $this->geo['gurugram_c']]);

        $this->assertTrue($this->resolver->resolve(1, '122001')['serviceable']);

        $cityless = $this->resolver->resolve(1, '122002');
        $this->assertFalse($cityless['serviceable']);
        $this->assertSame('not_covered', $cityless['reason']);
    }

    public function test_a_named_vertical_replaces_the_default_rather_than_adding_to_it(): void
    {
        // Default: the whole of Haryana. Tools: Gurgaon district only.
        $this->coverage->addCoverage(1, 'state', ['state_id' => $this->geo['haryana']]);
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon'], 'vertical' => 'tools']);

        // Faridabad is in the default set but NOT in the tools set.
        $this->assertTrue($this->resolver->resolve(1, '121001', 'plants')['serviceable'], 'a vertical with no rules falls back to *');
        $this->assertTrue($this->resolver->resolve(1, '122001', 'tools')['serviceable']);

        $tools = $this->resolver->resolve(1, '121001', 'tools');
        $this->assertFalse($tools['serviceable'], 'tools must not inherit the * state rule');
        $this->assertSame('tools', $tools['vertical_scope']);

        // vendorsFor agrees with resolve() — it is the same question asked for every vendor.
        $this->assertSame([1], array_keys($this->resolver->vendorsFor('122001', 'tools')));
        $this->assertSame([], array_keys($this->resolver->vendorsFor('121001', 'tools')));
        $this->assertSame([1], array_keys($this->resolver->vendorsFor('121001', 'plants')));
    }

    public function test_the_delivery_promise_comes_from_the_rule_that_won_the_pin(): void
    {
        // State says courier/7; the city rule inside it says local/1 and wins.
        $this->coverage->addCoverage(1, 'state', ['state_id' => $this->geo['haryana'], 'fulfillment_mode' => 'courier', 'eta_days' => 7]);
        $this->coverage->addCoverage(1, 'city', ['city_id' => $this->geo['gurugram_c'], 'fulfillment_mode' => 'local', 'eta_days' => 1]);

        $city = $this->resolver->resolve(1, '122001');
        $this->assertSame(['local', 1, 'next_day'], [$city['fulfillment_mode'], $city['eta_days'], $city['sla_bucket']]);

        $state = $this->resolver->resolve(1, '121001');
        $this->assertSame(['courier', 7, '3_5'], [$state['fulfillment_mode'], $state['eta_days'], $state['sla_bucket']]);
    }

    public function test_a_rule_without_a_promise_falls_back_to_the_shop_then_the_constant(): void
    {
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);

        // No mode declared → 'both' → the courier constant.
        $this->assertSame(VendorServiceabilityResolver::DEFAULT_COURIER_ETA, $this->resolver->resolve(1, '122001')['eta_days']);

        DB::table('shops')->where('id', 1)->update(['sla_default_days' => 3]);
        $this->assertSame(3, $this->resolver->resolve(1, '122001')['eta_days']);
    }

    public function test_excluding_a_whole_district_needs_one_rule_not_hundreds(): void
    {
        $this->coverage->addCoverage(1, 'state', ['state_id' => $this->geo['haryana']]);
        $this->coverage->addCoverage(1, 'district_exclude', ['district_id' => $this->geo['faridabad_d']]);

        $this->assertTrue($this->resolver->resolve(1, '122001')['serviceable']);
        $this->assertFalse($this->resolver->resolve(1, '121001')['serviceable']);
        $this->assertFalse($this->resolver->resolve(1, '121002')['serviceable']);
    }

    public function test_unknown_inactive_and_malformed_pins_each_say_why(): void
    {
        $this->coverage->addCoverage(1, 'state', ['state_id' => $this->geo['haryana']]);

        $this->assertSame('invalid_pincode', $this->resolver->resolve(1, '12a')['reason']);
        $this->assertSame('unknown_pincode', $this->resolver->resolve(1, '999999')['reason']);
        $this->assertSame('pincode_inactive', $this->resolver->resolve(1, '122099')['reason']);
    }

    public function test_an_inactive_vendor_never_serves_anywhere(): void
    {
        $this->coverage->addCoverage(2, 'state', ['state_id' => $this->geo['haryana']]);

        $this->assertSame('vendor_inactive', $this->resolver->resolve(2, '122001')['reason']);
        $this->assertSame([], array_keys($this->resolver->vendorsFor('122001')));
    }

    public function test_configured_for_is_scoped_to_the_pins_state(): void
    {
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);

        $this->assertTrue($this->resolver->configuredFor('122001'));
        $this->assertTrue($this->resolver->configuredFor('121001'), 'same state, uncovered pin — still configured');
        $this->assertFalse($this->resolver->configuredFor('302001'), 'another state must stay fail-open');
    }

    public function test_configured_for_a_vertical_ignores_another_verticals_rules(): void
    {
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['jaipur_d'], 'vertical' => 'tools']);

        $this->assertTrue($this->resolver->configuredFor('302001', 'tools'));
        $this->assertTrue($this->resolver->configuredFor('302001'), 'no vertical asked = any vertical counts');
        $this->assertFalse($this->resolver->configuredFor('122001', 'tools'), 'another state');
    }
}
