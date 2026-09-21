<?php

declare(strict_types=1);

namespace Tests\Feature\Tax;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Repositories\CheckoutRepository;
use Marvel\Services\Tax\GstService;
use Marvel\Services\VariantResolver;

/**
 * Delivery is charged by SIZE (Small 100 / Medium 150 / Large 200), per unit,
 * from the variant master — replacing a single flat figure per product.
 *
 * Two things have to hold for that to be safe: the size has to be resolvable for
 * BOTH shapes of variation_options.options in production (admin-built rows carry
 * the attribute_values id, seeded rows carry only the value text), and a failure
 * anywhere in that lookup has to fall back to the product's own charge — the
 * caller wraps the whole delivery path in a catch that returns 0, so an
 * exception here would hand out free delivery on every order.
 */
final class VariantDeliveryTest extends TaxTestCase
{
    private function checkout(): CheckoutRepository
    {
        return app(CheckoutRepository::class);
    }

    private function cartLine(int $productId, int $variationOptionId, float $price = 500.0, int $qty = 1): array
    {
        return [
            'product_id' => $productId,
            'variation_option_id' => $variationOptionId,
            'order_quantity' => $qty,
            'unit_price' => $price,
            'subtotal' => $price * $qty,
        ];
    }

    public function test_each_size_is_charged_its_own_delivery(): void
    {
        $sizes = $this->sizeMaster();
        $pid = $this->product(null);
        $small = $this->variationOption($pid, 'Small', $sizes['Small']);
        $large = $this->variationOption($pid, 'Large', $sizes['Large']);

        $total = $this->checkout()->perLineDelivery([
            $this->cartLine($pid, $small),
            $this->cartLine($pid, $large),
        ]);

        $this->assertSame(100.0, $total[$pid . ':' . $small]);
        $this->assertSame(200.0, $total[$pid . ':' . $large]);
        $this->assertSame(300.0, array_sum($total));
    }

    public function test_the_charge_is_per_unit(): void
    {
        $sizes = $this->sizeMaster();
        $pid = $this->product(null);
        $vid = $this->variationOption($pid, 'Medium', $sizes['Medium']);

        $charges = $this->checkout()->perLineDelivery([$this->cartLine($pid, $vid, 500.0, 3)]);

        $this->assertSame(450.0, $charges[$pid . ':' . $vid], 'three Medium plants ship as three parcels');
    }

    public function test_a_seeded_option_carrying_no_id_still_resolves_by_its_value(): void
    {
        // PlantAtHomePotSeeder and both ApplySizePricing commands write this shape.
        $this->sizeMaster();
        $pid = $this->product(null);
        $vid = $this->variationOption($pid, 'Large', null);

        $charges = $this->checkout()->perLineDelivery([$this->cartLine($pid, $vid)]);

        $this->assertSame(200.0, $charges[$pid . ':' . $vid]);
    }

    public function test_a_stale_id_falls_through_to_the_value_rather_than_mispricing(): void
    {
        // The 2026-09-17 duplicate-attribute merge re-pointed pivots but never
        // rewrote this JSON, so an id here can name a row that no longer exists.
        $this->sizeMaster();
        $pid = $this->product(null);
        $vid = DB::table('variation_options')->insertGetId([
            'product_id' => $pid,
            'title' => 'Small',
            'options' => json_encode([['name' => 'Size', 'value' => 'Small', 'id' => 987654]]),
        ]);

        $charges = $this->checkout()->perLineDelivery([$this->cartLine($pid, $vid)]);

        $this->assertSame(100.0, $charges[$pid . ':' . $vid]);
    }

    public function test_a_product_without_a_size_keeps_its_own_charge(): void
    {
        $this->sizeMaster();
        $pid = DB::table('products')->insertGetId(['delivery_charge' => 75.0, 'is_taxable' => 0]);

        $charges = $this->checkout()->perLineDelivery([[
            'product_id' => $pid,
            'variation_option_id' => null,
            'order_quantity' => 2,
            'unit_price' => 300.0,
            'subtotal' => 600.0,
        ]]);

        $this->assertSame(150.0, $charges[$pid . ':0']);
    }

    public function test_an_unpriced_size_falls_back_to_the_product_charge(): void
    {
        $sizes = $this->sizeMaster(['Small' => 100.0]);
        DB::table('attribute_values')->where('id', $sizes['Small'])->update(['delivery_charge' => null]);
        $pid = DB::table('products')->insertGetId(['delivery_charge' => 60.0, 'is_taxable' => 0]);
        $vid = $this->variationOption($pid, 'Small', $sizes['Small']);

        $charges = $this->checkout()->perLineDelivery([$this->cartLine($pid, $vid)]);

        $this->assertSame(60.0, $charges[$pid . ':' . $vid]);
    }

    public function test_delivery_is_never_free_just_because_the_variant_lookup_broke(): void
    {
        $this->sizeMaster();
        $pid = DB::table('products')->insertGetId(['delivery_charge' => 90.0, 'is_taxable' => 0]);
        $vid = $this->variationOption($pid, 'Large');
        DB::statement('DROP TABLE variation_options'); // the lookup now throws

        $charges = $this->checkout()->perLineDelivery([$this->cartLine($pid, $vid)]);

        $this->assertSame(90.0, $charges[$pid . ':' . $vid]);
    }

    public function test_freight_tax_follows_what_each_parcel_costs_not_what_it_is_worth(): void
    {
        // A cheap 18% pot shipped Small beside an expensive 0% plant shipped Large.
        // Weighted by VALUE the freight rate leans 0%; weighted by what is actually
        // being shipped it leans the other way, which is what the customer pays for.
        $this->business();
        $sizes = $this->sizeMaster();
        $potId = $this->product($this->taxRate(18.0), '3924');
        $plantId = $this->product($this->taxRate(0.0), '0602');

        $lines = [
            ['product_id' => $potId,   'variation_option_id' => null, 'order_quantity' => 1, 'unit_price' => 200.0, 'subtotal' => 200.0, 'delivery_fee' => 100.0],
            ['product_id' => $plantId, 'variation_option_id' => null, 'order_quantity' => 1, 'unit_price' => 2000.0, 'subtotal' => 2000.0, 'delivery_fee' => 200.0],
        ];
        $byParcel = (new GstService())->compute($lines, 300.0, $this->shipTo('Haryana'));

        foreach ($lines as &$line) {
            unset($line['delivery_fee']);
        }
        unset($line);
        $byValue = (new GstService())->compute($lines, 300.0, $this->shipTo('Haryana'));

        // by value: 18% on 200 of 2200 taxable-ish → ~1.6%; by parcel: 18% on 100 of 300 → 6%.
        $this->assertGreaterThan(
            $byValue['delivery_tax_amount'],
            $byParcel['delivery_tax_amount'],
            'freight should follow the goods it carries, not their price'
        );
        $this->assertSame(300.0, round($byParcel['delivery_taxable'] + $byParcel['delivery_tax_amount'], 2));
    }

    public function test_the_resolver_reports_the_master_code(): void
    {
        $sizes = $this->sizeMaster();
        $pid = $this->product(null);
        $vid = $this->variationOption($pid, 'Medium', $sizes['Medium']);

        $this->assertSame('M', (new VariantResolver())->sizeValueFor($vid)->code);
        $this->assertNull((new VariantResolver())->sizeValueFor(null));
        $this->assertNull((new VariantResolver())->deliveryChargeFor(999999), 'a deleted option resolves to nothing');
    }
}
