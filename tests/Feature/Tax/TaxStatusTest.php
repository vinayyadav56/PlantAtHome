<?php

declare(strict_types=1);

namespace Tests\Feature\Tax;

use Marvel\Services\Tax\GstService;

/**
 * Every line reports whether its tax is actually configured.
 *
 * The engine has always refused to invent a rate — an unconfigured product is
 * 0%, never a guessed 18% — but it said so silently, so nothing downstream could
 * tell "genuinely zero-rated" from "nobody has filled this in". That difference
 * is the whole Missing Tax Config report, and the checkout gate.
 */
final class TaxStatusTest extends TaxTestCase
{
    private function statusOf(int $productId): string
    {
        $gst = (new GstService())->compute([$this->line($productId, 500.0)], 0.0, $this->shipTo('Haryana'));

        return $gst['lines'][0]['tax_status'];
    }

    private function verified(int $productId): void
    {
        \Illuminate\Support\Facades\DB::table('products')->where('id', $productId)->update(['tax_verified' => 1]);
    }

    public function test_a_configured_and_verified_product_is_configured(): void
    {
        $this->business();
        $product = $this->product($this->taxRate(18.0), '3924');
        $this->verified($product);

        $this->assertSame('configured', $this->statusOf($product));
    }

    public function test_a_product_with_no_rate_anywhere_is_unconfigured(): void
    {
        $this->business();

        $this->assertSame('unconfigured', $this->statusOf($this->product(null)));
    }

    public function test_a_zero_rated_product_is_configured_not_unconfigured(): void
    {
        // The distinction the report lives or dies on: a plant really is 0%.
        $this->business();
        $product = $this->product($this->taxRate(0.0), '0602');
        $this->verified($product);

        $this->assertSame('configured', $this->statusOf($product));
    }

    public function test_an_unverified_product_says_so_even_with_enforcement_off(): void
    {
        // A status that only tells the truth once the gate is switched on is no
        // use for deciding whether the gate can be switched on.
        $this->business(['enforce_tax_verified' => false]);
        $product = $this->product($this->taxRate(18.0), '3924');

        $this->assertSame('unverified', $this->statusOf($product));
        $this->assertSame(18.0, (new GstService())
            ->compute([$this->line($product, 500.0)], 0.0, $this->shipTo('Haryana'))['lines'][0]['tax_rate']);
    }

    public function test_enforcement_zeroes_an_unverified_product_but_keeps_the_reason_visible(): void
    {
        $this->business(['enforce_tax_verified' => true]);
        $product = $this->product($this->taxRate(18.0), '3924');

        $gst = (new GstService())->compute([$this->line($product, 500.0)], 0.0, $this->shipTo('Haryana'));

        $this->assertSame(0.0, $gst['lines'][0]['tax_rate'], 'not billed at a rate nobody signed off');
        $this->assertSame('unverified', $gst['lines'][0]['tax_status'], 'and it is clear WHY it is zero');
    }

    public function test_an_expired_rate_reads_as_unconfigured(): void
    {
        $this->business();
        $rate = $this->taxRate(18.0, 'taxable', '3924', [
            'effective_to' => now()->subDay()->toDateString(),
        ]);
        $product = $this->product($rate);
        $this->verified($product);

        $this->assertSame('unconfigured', $this->statusOf($product));
    }

    public function test_the_breakdown_is_stamped_with_the_engine_revision(): void
    {
        $this->business();
        $gst = (new GstService())->compute([$this->line($this->product(null), 500.0)], 0.0, $this->shipTo('Haryana'));

        $this->assertSame(GstService::VERSION, $gst['tax_calc_version']);
    }
}
