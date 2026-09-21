<?php

declare(strict_types=1);

namespace Tests\Feature\Tax;

use Illuminate\Support\Carbon;
use Marvel\Services\Tax\GstService;

/**
 * Scheduled rate changes.
 *
 * A GST revision is announced with a date. Until now the only way to follow one
 * was to edit the rate by hand on the morning it took effect — which has to beat
 * the first order of the day, and reprices everything the instant it is saved.
 * A version says "this class is R% from D" and the engine picks it up on the day.
 *
 * Products keep pointing at the same tax_classes row throughout: nothing is
 * re-pointed, and orders already placed keep their snapshotted rate regardless.
 */
final class TaxRateVersionTest extends TaxTestCase
{
    private function taxOn(int $productId, float $price = 1000.0): array
    {
        return (new GstService())->compute(
            [$this->line($productId, $price)],
            0.0,
            $this->shipTo('Haryana')
        );
    }

    public function test_a_class_with_no_versions_keeps_its_own_rate(): void
    {
        // Which is every class that exists today.
        $this->business();
        $product = $this->product($this->taxRate(18.0));

        $this->assertSame(18.0, $this->taxOn($product)['lines'][0]['tax_rate']);
    }

    public function test_a_change_scheduled_for_tomorrow_does_not_price_today(): void
    {
        $this->business();
        $rateId = $this->taxRate(18.0);
        $this->scheduleRate($rateId, 5.0, Carbon::tomorrow()->toDateString());
        $product = $this->product($rateId);

        $this->assertSame(18.0, $this->taxOn($product)['lines'][0]['tax_rate']);
    }

    public function test_it_takes_effect_on_the_day_with_nobody_touching_anything(): void
    {
        $this->business();
        $rateId = $this->taxRate(18.0);
        $this->scheduleRate($rateId, 5.0, Carbon::tomorrow()->toDateString());
        $product = $this->product($rateId);

        Carbon::setTestNow(Carbon::tomorrow()->startOfDay());
        try {
            $this->assertSame(5.0, $this->taxOn($product)['lines'][0]['tax_rate']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_the_latest_change_on_or_before_today_wins(): void
    {
        $this->business();
        $rateId = $this->taxRate(28.0);
        $this->scheduleRate($rateId, 18.0, Carbon::today()->subYear()->toDateString());
        $this->scheduleRate($rateId, 12.0, Carbon::today()->subMonth()->toDateString());
        $this->scheduleRate($rateId, 5.0, Carbon::tomorrow()->toDateString());
        $product = $this->product($rateId);

        $this->assertSame(12.0, $this->taxOn($product)['lines'][0]['tax_rate']);
    }

    public function test_an_expired_version_falls_back_to_the_class_rate(): void
    {
        $this->business();
        $rateId = $this->taxRate(18.0);
        $this->scheduleRate(
            $rateId,
            5.0,
            Carbon::today()->subMonths(6)->toDateString(),
            Carbon::today()->subMonth()->toDateString()
        );
        $product = $this->product($rateId);

        $this->assertSame(18.0, $this->taxOn($product)['lines'][0]['tax_rate']);
    }

    public function test_the_split_follows_the_scheduled_rate(): void
    {
        // Not just the headline number — CGST/SGST derive from it, so a version
        // that moved the rate but not the split would be worse than no version.
        $this->business();
        $rateId = $this->taxRate(18.0);
        $this->scheduleRate($rateId, 12.0, Carbon::today()->subDay()->toDateString());
        $product = $this->product($rateId);

        $line = $this->taxOn($product, 1120.0)['lines'][0];

        $this->assertSame(12.0, $line['tax_rate']);
        $this->assertSame(6.0, $line['cgst_rate']);
        $this->assertSame(6.0, $line['sgst_rate']);
        $this->assertSame(1000.0, $line['taxable_value'], 'inclusive: 1120 at 12% is 1000 + 120');
        $this->assertSame(120.0, $line['tax_amount']);
    }

    public function test_a_scheduled_change_never_reprices_an_order_already_placed(): void
    {
        // The line snapshot is read verbatim, never recomputed.
        $this->business();
        $snapshot = [(object) [
            'taxable_value' => 1000.0, 'tax_amount' => 180.0,
            'cgst_amount' => 90.0, 'sgst_amount' => 90.0, 'igst_amount' => 0.0,
        ]];

        $this->assertSame(180.0, GstService::sumSnapshotTax($snapshot)['tax']);
    }
}
