<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use Marvel\Database\Repositories\OrderRepository;
use ReflectionMethod;

/**
 * Delivery is charged by size, so each line's own charge IS its allocation —
 * but only while the parts still add up to what the order charged.
 *
 * A free-shipping coupon, the free-delivery threshold and the Delivery
 * Optimizer's consolidated fee each replace the order's delivery_fee wholesale,
 * and Σ order_items.delivery_allocation has to keep equalling orders.delivery_fee
 * or the ledger stops balancing against it. Those cases fall back to the
 * largest-remainder split that was there before.
 */
final class DeliveryAllocationTest extends OrdersTestCase
{
    /** @param array<int, array<string, mixed>> $lines */
    private function merge(array $lines, float $deliveryFee, float $discount = 0.0): array
    {
        $method = new ReflectionMethod(OrderRepository::class, 'mergeLineFinancials');
        $method->setAccessible(true);

        return $method->invoke(app(OrderRepository::class), $lines, $discount, $deliveryFee, null);
    }

    /** Small (100) + Large (200), priced so a value-weighted split would differ sharply. */
    private function lines(?float $smallCharge = 100.0, ?float $largeCharge = 200.0): array
    {
        $small = ['product_id' => 4041, 'variation_option_id' => 1, 'order_quantity' => 1, 'unit_price' => 300.0, 'subtotal' => 300.0];
        $large = ['product_id' => 4042, 'variation_option_id' => 2, 'order_quantity' => 1, 'unit_price' => 2700.0, 'subtotal' => 2700.0];
        if ($smallCharge !== null) {
            $small['delivery_fee'] = $smallCharge;
        }
        if ($largeCharge !== null) {
            $large['delivery_fee'] = $largeCharge;
        }

        return [$small, $large];
    }

    public function test_each_line_keeps_the_charge_it_was_quoted(): void
    {
        $out = $this->merge($this->lines(), 300.0);

        $this->assertSame(100.0, $out[0]['delivery_allocation']);
        $this->assertSame(200.0, $out[1]['delivery_allocation']);
    }

    public function test_a_free_shipping_order_still_reconciles(): void
    {
        $out = $this->merge($this->lines(), 0.0);

        $this->assertSame(0.0, array_sum(array_column($out, 'delivery_allocation')));
    }

    public function test_a_replaced_fee_falls_back_to_the_proportional_split(): void
    {
        // The optimizer charges one consolidated 149.00 regardless of the per-size
        // quotes, so the quoted 100/200 no longer describes this order.
        $out = $this->merge($this->lines(), 149.00);

        $allocations = array_column($out, 'delivery_allocation');
        $this->assertEqualsWithDelta(149.00, array_sum($allocations), 0.001);
        $this->assertNotSame(100.0, $allocations[0], 'the quoted charge does not survive a replaced fee');
        // weighted by subtotal: 300/3000 and 2700/3000
        $this->assertEqualsWithDelta(14.90, $allocations[0], 0.01);
        $this->assertEqualsWithDelta(134.10, $allocations[1], 0.01);
    }

    public function test_lines_with_no_quoted_charge_use_the_proportional_split(): void
    {
        $out = $this->merge($this->lines(null, null), 300.0);

        $allocations = array_column($out, 'delivery_allocation');
        $this->assertEqualsWithDelta(300.0, array_sum($allocations), 0.001);
        $this->assertEqualsWithDelta(30.0, $allocations[0], 0.01);
    }

    public function test_a_partially_quoted_cart_is_not_trusted(): void
    {
        // One line quoted and one not cannot reconcile; splitting on a half-filled
        // map would silently under-allocate the unquoted line.
        $out = $this->merge($this->lines(100.0, null), 300.0);

        $this->assertEqualsWithDelta(300.0, array_sum(array_column($out, 'delivery_allocation')), 0.001);
        $this->assertNotSame(100.0, $out[0]['delivery_allocation']);
    }

    public function test_the_discount_split_is_untouched_by_any_of_this(): void
    {
        $out = $this->merge($this->lines(), 300.0, 150.0);

        $this->assertEqualsWithDelta(150.0, array_sum(array_column($out, 'discount_amount')), 0.001);
        $this->assertSame('platform', $out[0]['discount_funded_by']);
    }
}
