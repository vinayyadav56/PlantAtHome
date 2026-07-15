<?php

namespace Tests\Feature\Serviceability;

use App\Modules\Serviceability\Application\DeliveryCoverageService;
use App\Modules\Serviceability\Infrastructure\Models\VendorCoverageRule;
use App\Shared\Application\DomainActionException;
use Illuminate\Support\Facades\DB;

/**
 * Delivery Coverage rule → projection semantics: the ascending priority
 * ladder (state → district → city → include, exclude unsets), input
 * validation, and the pincode-facing reads.
 */
class DeliveryCoverageServiceTest extends ServiceabilityTestCase
{
    use SeedsCoverageGeo;

    private DeliveryCoverageService $coverage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCoverageGeo();
        $this->coverage = $this->app->make(DeliveryCoverageService::class);
    }

    /** Insert a rule bypassing service validation (simulates dataset drift). */
    private function rawRule(int $shopId, string $type, array $attrs, bool $active = true): VendorCoverageRule
    {
        return VendorCoverageRule::create($attrs + [
            'shop_id'    => $shopId,
            'rule_type'  => $type,
            'target_key' => VendorCoverageRule::targetKey($type, $attrs['pincode'] ?? $attrs['state_id'] ?? $attrs['district_id'] ?? $attrs['city_id']),
            'is_active'  => $active,
        ]);
    }

    public function test_priority_ladder_truth_table(): void
    {
        // Shop 1: state Haryana + district Gurgaon + city Faridabad +
        // include 302001 (Rajasthan) + exclude 122002, and an INACTIVE
        // state-Rajasthan rule that must be ignored.
        $this->coverage->addCoverage(1, 'state', ['state_id' => $this->geo['haryana']]);
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);
        $this->coverage->addCoverage(1, 'city', ['city_id' => $this->geo['faridabad_c']]);
        $this->coverage->addCoverage(1, 'pincode_include', ['pincode' => '302001']);
        $this->coverage->addCoverage(1, 'pincode_exclude', ['pincode' => '122002']);
        $this->rawRule(1, 'state', ['state_id' => $this->geo['rajasthan']], active: false);
        $this->coverage->syncCoverage(1);

        $truth = [
            // pincode        deliverable  source      why
            ['122001',        true,        'district'], // district overrides state
            ['122002',        false,       null],       // exclude unsets (state+district covered it)
            ['121001',        true,        'city'],     // city overrides state
            ['121002',        true,        'state'],    // only the state tier covers it
            ['302001',        true,        'manual'],   // include pin in a foreign state
            ['302002',        false,       null],       // inactive rule ignored
            ['122099',        false,       null],       // postal row status != active never projects
            ['999999',        false,       null],       // not in the postal master
            [' 1220-01 ',     true,        'district'], // input normalized to digits
        ];
        foreach ($truth as [$pin, $deliverable, $source]) {
            $this->assertSame($deliverable, $this->coverage->isDeliverable(1, $pin), "isDeliverable({$pin})");
            $this->assertSame($source, $this->coverage->resolveSource(1, $pin), "resolveSource({$pin})");
        }

        // A vendor with no rules covers nothing.
        $this->assertFalse($this->coverage->isDeliverable(2, '122001'));
        $this->assertNull($this->coverage->resolveSource(2, '122001'));
    }

    public function test_include_plus_exclude_of_the_same_pin_is_not_deliverable(): void
    {
        $this->coverage->addCoverage(2, 'pincode_include', ['pincode' => '302002']);
        $this->coverage->addCoverage(2, 'pincode_exclude', ['pincode' => '302002']);

        $this->assertFalse($this->coverage->isDeliverable(2, '302002'));
        $this->assertNull($this->coverage->resolveSource(2, '302002'));
    }

    public function test_unknown_include_pin_is_reported_in_sync_stats_and_not_deliverable(): void
    {
        // Bypass validation: an include rule whose pin later left the master.
        $this->rawRule(2, 'pincode_include', ['pincode' => '888888']);
        $stats = $this->coverage->syncCoverage(2);

        $this->assertSame(['888888'], $stats['unknown_pincodes']);
        $this->assertSame(0, $stats['pincodes']);
        $this->assertFalse($this->coverage->isDeliverable(2, '888888'));
    }

    public function test_invalid_input_throws_domain_action_exceptions(): void
    {
        $cases = [
            ['galaxy', [], 'INVALID_RULE_TYPE'],
            ['state', ['state_id' => 999], 'TARGET_NOT_FOUND'],
            ['district', [], 'TARGET_NOT_FOUND'],
            ['pincode_include', ['pincode' => '12'], 'INVALID_PINCODE'],
            ['pincode_include', ['pincode' => '999999'], 'PINCODE_UNKNOWN'], // not in master
        ];
        foreach ($cases as [$type, $target, $code]) {
            try {
                $this->coverage->addCoverage(1, $type, $target);
                $this->fail("Expected DomainActionException ({$code}) for {$type}");
            } catch (DomainActionException $e) {
                $this->assertSame($code, $e->errorCode());
            }
        }

        // Excluding a pin we do not know about is allowed (format-valid).
        $rule = $this->coverage->addCoverage(1, 'pincode_exclude', ['pincode' => '999999']);
        $this->assertSame('pincode_exclude:999999', $rule->target_key);
    }

    public function test_available_nurseries_join_active_shops_only(): void
    {
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]); // active shop
        $this->coverage->addCoverage(2, 'pincode_include', ['pincode' => '122001']);           // inactive shop

        $this->assertSame([1], $this->coverage->getAvailableNurseryIds('122001'));

        // The diagnostic rows read the raw projection (both vendors visible).
        $rows = $this->coverage->getAvailableNurseries('122001');
        $this->assertSame([1, 2], array_column($rows, 'shop_id'));
        $this->assertSame(['district', 'manual'], array_column($rows, 'source'));
        $this->assertSame($this->geo['gurgaon'], $rows[0]['district_id']);
    }

    public function test_covered_pincodes_paginate_and_any_coverage_configured_flips(): void
    {
        $this->assertFalse($this->coverage->anyCoverageConfigured());

        $this->coverage->addCoverage(1, 'state', ['state_id' => $this->geo['haryana']]);

        $this->assertTrue($this->coverage->anyCoverageConfigured());

        $page = $this->coverage->getCoveredPincodes(1, perPage: 3, page: 1);
        $this->assertSame(4, $page->total()); // 122001, 122002, 121001, 121002
        $this->assertCount(3, $page->items());
        $this->assertSame('121001', $page->items()[0]->pincode); // ordered by pincode
        $this->assertCount(1, $this->coverage->getCoveredPincodes(1, perPage: 3, page: 2)->items());
    }

    public function test_coverage_summary_resolves_names_and_totals(): void
    {
        $this->coverage->addCoverage(1, 'state', ['state_id' => $this->geo['haryana']]);
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);
        $this->coverage->addCoverage(1, 'city', ['city_id' => $this->geo['faridabad_c']]);
        $this->coverage->addCoverage(1, 'pincode_include', ['pincode' => '302001']);
        $this->coverage->addCoverage(1, 'pincode_exclude', ['pincode' => '122002']);

        $summary = $this->coverage->getCoverageSummary(1);

        $this->assertSame('Haryana', $summary['rules']['state'][0]['target']);
        $this->assertSame('Gurgaon', $summary['rules']['district'][0]['target']);
        $this->assertSame('Faridabad', $summary['rules']['city'][0]['target']);
        $this->assertSame(4, $summary['totals']['covered_pincodes']);
        $this->assertSame(
            ['city' => 1, 'district' => 1, 'manual' => 1, 'state' => 1],
            collect($summary['totals']['by_source'])->sortKeys()->all(),
        );
        $this->assertSame(2, $summary['totals']['states_covered']);    // Haryana + Rajasthan (via include)
        $this->assertSame(3, $summary['totals']['districts_covered']);
        $this->assertSame(['302001'], $summary['manual_added']);
        $this->assertSame(['122002'], $summary['manual_removed']);
        $this->assertNotNull($summary['last_synced']);

        $this->assertSame(5, DB::table('coverage_audit_logs')->where('shop_id', 1)->where('action', 'rule_added')->count());
    }

    public function test_remove_coverage_requires_owning_vendor(): void
    {
        $rule = $this->coverage->addCoverage(1, 'state', ['state_id' => $this->geo['haryana']]);

        try {
            $this->coverage->removeCoverage(2, $rule->id); // someone else's rule
            $this->fail('Expected COVERAGE_RULE_NOT_FOUND');
        } catch (DomainActionException $e) {
            $this->assertSame('COVERAGE_RULE_NOT_FOUND', $e->errorCode());
        }

        $this->coverage->removeCoverage(1, $rule->id);
        $this->assertFalse($this->coverage->isDeliverable(1, '122001'));
        $this->assertSame(1, DB::table('coverage_audit_logs')->where('shop_id', 1)->where('action', 'rule_removed')->count());
    }
}
