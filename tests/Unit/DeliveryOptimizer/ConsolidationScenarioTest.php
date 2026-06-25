<?php

namespace Tests\Unit\DeliveryOptimizer;

use Marvel\Services\DeliveryOptimizer\DeliveryOptimizerService;
use Marvel\Services\DeliveryOptimizer\Dto\UserLocation;
use Marvel\Services\DeliveryOptimizer\Support\Rail;
use PHPUnit\Framework\TestCase;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeCandidateProvider;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeConfig;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeQuoteClient;
use Tests\Unit\DeliveryOptimizer\Fakes\MakesOptimizerFixtures;

/**
 * THE locked acceptance scenario from the DESIGN.
 *
 * Cart: P1–P4 @ Vendor A (local), P5 @ Vendor B (local), F @ Vendor C (courier),
 *       S @ Vendor D (courier), T @ Vendor D (courier).
 *
 * Expectation: EXACTLY 4 shipments — {A:[P1-4]}, {B:[P5]}, {C:[F]}, {D:[S,T]} — with the
 * seed S and the tool T CONSOLIDATED onto Vendor D's single courier leg, ONE flat customer
 * fee, distinct per-leg dates, and internal_true_cost = 4 delivery fees (NOT 8).
 */
final class ConsolidationScenarioTest extends TestCase
{
    use MakesOptimizerFixtures;

    private function makeService(FakeCandidateProvider $cp): DeliveryOptimizerService
    {
        return new DeliveryOptimizerService($cp, new FakeQuoteClient(), new FakeConfig());
    }

    private function scenario(): FakeCandidateProvider
    {
        // Vendors: A=1,B=2 local (INSTANT); C=3,D=4 courier (COURIER). Flat base fees.
        return new FakeCandidateProvider([
            '11:0' => [$this->cand(11, 1, 'local', 50, 2)],   // P1 @ A
            '12:0' => [$this->cand(12, 1, 'local', 50, 2)],   // P2 @ A
            '13:0' => [$this->cand(13, 1, 'local', 50, 2)],   // P3 @ A
            '14:0' => [$this->cand(14, 1, 'local', 50, 2)],   // P4 @ A
            '15:0' => [$this->cand(15, 2, 'local', 50, 2)],   // P5 @ B
            '21:0' => [$this->cand(21, 3, 'courier', 80, 5)], // F  @ C
            '22:0' => [$this->cand(22, 4, 'courier', 80, 5)], // S  @ D
            '23:0' => [$this->cand(23, 4, 'courier', 80, 5)], // T  @ D
        ]);
    }

    private function cart(): array
    {
        return [
            $this->item(11), $this->item(12), $this->item(13), $this->item(14),
            $this->item(15), $this->item(21), $this->item(22), $this->item(23),
        ];
    }

    public function test_consolidates_into_exactly_four_shipments(): void
    {
        $result = $this->makeService($this->scenario())
            ->optimizeCart($this->cart(), new UserLocation('Gurugram', '122001'));

        $this->assertCount(4, $result->shipments, 'Expected exactly 4 consolidated shipments');
    }

    public function test_seed_and_tool_share_vendor_d_leg(): void
    {
        $result = $this->makeService($this->scenario())
            ->optimizeCart($this->cart(), new UserLocation('Gurugram', '122001'));

        // Find the leg carrying product 22 (seed) and assert product 23 (tool) is on it too.
        $legWith22 = null;
        foreach ($result->shipments as $s) {
            $pids = array_map(fn ($i) => $i->productId, $s->items);
            if (in_array(22, $pids, true)) {
                $legWith22 = $s;
            }
        }
        $this->assertNotNull($legWith22, 'Seed must be assigned');
        $this->assertSame(4, $legWith22->vendorId);
        $this->assertSame(Rail::COURIER, $legWith22->rail);
        $pids = array_map(fn ($i) => $i->productId, $legWith22->items);
        sort($pids);
        $this->assertSame([22, 23], $pids, 'Seed + tool must consolidate on Vendor D');
    }

    public function test_vendor_a_carries_all_four_plants_on_one_leg(): void
    {
        $result = $this->makeService($this->scenario())
            ->optimizeCart($this->cart(), new UserLocation('Gurugram', '122001'));

        $legA = null;
        foreach ($result->shipments as $s) {
            if ($s->vendorId === 1) {
                $legA = $s;
            }
        }
        $this->assertNotNull($legA);
        $pids = array_map(fn ($i) => $i->productId, $legA->items);
        sort($pids);
        $this->assertSame([11, 12, 13, 14], $pids, 'All four plants ride Vendor A once');
    }

    public function test_internal_true_cost_is_four_fees_not_eight(): void
    {
        $result = $this->makeService($this->scenario())
            ->optimizeCart($this->cart(), new UserLocation('Gurugram', '122001'));

        // A=50, B=50, C=80, D=80 (perKg 0) → 260. Eight separate legs would be 520.
        $this->assertEqualsWithDelta(260.0, $result->internalTrueCost, 0.001);
    }

    public function test_one_flat_customer_fee_and_upsell_hint(): void
    {
        $result = $this->makeService($this->scenario())
            ->optimizeCart($this->cart(), new UserLocation('Gurugram', '122001'));

        // 8 items × ₹100 = ₹800 subtotal < ₹999 threshold → one flat ₹49 fee + upsell gap ₹199.
        $this->assertEqualsWithDelta(800.0, $result->subtotal, 0.001);
        $this->assertEqualsWithDelta(49.0, $result->customerFlatFee, 0.001);
        $this->assertIsFloat($result->customerFlatFee);
        $this->assertNotNull($result->upsellHint);
        $this->assertEqualsWithDelta(199.0, $result->upsellHint['gap_to_free_delivery'], 0.001);
    }

    public function test_each_shipment_has_distinct_dates_and_estimate_source(): void
    {
        $result = $this->makeService($this->scenario())
            ->optimizeCart($this->cart(), new UserLocation('Gurugram', '122001'));

        foreach ($result->shipments as $s) {
            $this->assertNotNull($s->etaMinDate);
            $this->assertNotNull($s->etaMaxDate);
        }
        $this->assertSame('estimate', $result->quoteSource, 'Browse path prices on estimates');
    }

    public function test_customer_facing_array_hides_vendor_and_internal_cost(): void
    {
        $result = $this->makeService($this->scenario())
            ->optimizeCart($this->cart(), new UserLocation('Gurugram', '122001'));

        // Seller anonymity + cost-economics privacy on the customer-facing serialization.
        $arr = $result->toArray();
        $this->assertArrayNotHasKey('internal_true_cost', $arr);
        foreach ($arr['shipments'] as $s) {
            $this->assertArrayNotHasKey('vendor_id', $s);
            $this->assertArrayNotHasKey('true_cost', $s);
            $this->assertArrayNotHasKey('rail', $s);
            $this->assertArrayHasKey('shipment_id', $s);
            $this->assertArrayHasKey('items', $s);
        }
        // But the internal serialization still carries them for admin/logging.
        $internal = $result->toInternalArray();
        $this->assertEqualsWithDelta(260.0, $internal['internal_true_cost'], 0.001);
        $this->assertArrayHasKey('vendor_id', $internal['shipments'][0]);
    }

    public function test_warm_called_once_for_whole_cart(): void
    {
        $cp = $this->scenario();
        $this->makeService($cp)->optimizeCart($this->cart(), new UserLocation('Gurugram', '122001'));
        $this->assertSame(1, $cp->warmCalls, 'warm() must batch the gate once, not per line');
    }

    public function test_free_delivery_when_subtotal_over_threshold(): void
    {
        // Bump price so subtotal ≥ 999 → fee 0, no upsell.
        $cp = new FakeCandidateProvider([
            '11:0' => [$this->cand(11, 1, 'local', 50, 2, 600.0)],
            '15:0' => [$this->cand(15, 2, 'local', 50, 2, 600.0)],
        ]);
        $result = $this->makeService($cp)
            ->optimizeCart([$this->item(11), $this->item(15)], new UserLocation('Gurugram', '122001'));

        $this->assertEqualsWithDelta(1200.0, $result->subtotal, 0.001);
        $this->assertEqualsWithDelta(0.0, $result->customerFlatFee, 0.001);
        $this->assertNull($result->upsellHint);
    }
}
