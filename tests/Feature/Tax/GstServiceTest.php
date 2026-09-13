<?php

namespace Tests\Feature\Tax;

use Marvel\Services\Tax\GstService;

class GstServiceTest extends TaxTestCase
{
    private function gst(): GstService
    {
        return new GstService();
    }

    // Test 1 — Zero GST product (inclusive): ₹300 @ 0% → tax 0, taxable 300.
    public function test_zero_gst_product(): void
    {
        $this->business();
        $p = $this->product($this->taxRate(0), '0602', true);
        $r = $this->gst()->compute([$this->line($p, 300)], 0, $this->shipTo('Haryana'));

        $this->assertSame(0.0, $r['total_tax']);
        $this->assertSame(300.0, $r['taxable_amount']);
        $this->assertSame(0.0, $r['tax_addon']);
    }

    // Test 2 & 7 — inclusive 5%: a ₹210 line → taxable 200, tax 10.
    public function test_five_percent_inclusive_back_calc(): void
    {
        $this->business();
        $p = $this->product($this->taxRate(5), '3101', true);
        $r = $this->gst()->compute([$this->line($p, 210)], 0, $this->shipTo('Haryana'));

        $this->assertSame(200.0, $r['taxable_amount']);
        $this->assertSame(10.0, $r['total_tax']);
        $this->assertSame(0.0, $r['tax_addon']); // inclusive ⇒ nothing added on top
    }

    // Test 7 — inclusive 18%: ₹1180 → taxable 1000, GST 180.
    public function test_eighteen_percent_inclusive(): void
    {
        $this->business();
        $p = $this->product($this->taxRate(18), '3924', true);
        $r = $this->gst()->compute([$this->line($p, 1180)], 0, $this->shipTo('Haryana'));

        $this->assertSame(1000.0, $r['taxable_amount']);
        $this->assertSame(180.0, $r['total_tax']);
    }

    // Test 3 — 18% EXCLUSIVE: ₹500 base → tax 90 added on top.
    public function test_eighteen_percent_exclusive_adds_on_top(): void
    {
        $this->business(['prices_include_tax' => false]);
        $p = $this->product($this->taxRate(18), '3924', false);
        $r = $this->gst()->compute([$this->line($p, 500)], 0, $this->shipTo('Haryana'));

        $this->assertSame(500.0, $r['taxable_amount']);
        $this->assertSame(90.0, $r['total_tax']);
        $this->assertSame(90.0, $r['tax_addon']); // exclusive ⇒ added to payable
    }

    // Test 4 — intra-state 18% → CGST 9 + SGST 9, IGST 0.
    public function test_intra_state_splits_cgst_sgst(): void
    {
        $this->business(); // origin Haryana
        $p = $this->product($this->taxRate(18), '3924', false);
        $r = $this->gst()->compute([$this->line($p, 500)], 0, $this->shipTo('Haryana'));

        $this->assertFalse($r['is_inter_state']);
        $this->assertSame(45.0, $r['cgst_amount']);
        $this->assertSame(45.0, $r['sgst_amount']);
        $this->assertSame(0.0, $r['igst_amount']);
        $this->assertSame(90.0, $r['total_tax']);
    }

    // Test 5 — inter-state 18% → IGST 90, CGST/SGST 0.
    public function test_inter_state_uses_igst(): void
    {
        $this->business(); // origin Haryana; ship to Delhi
        $p = $this->product($this->taxRate(18), '3924', false);
        $r = $this->gst()->compute([$this->line($p, 500)], 0, $this->shipTo('Delhi'));

        $this->assertTrue($r['is_inter_state']);
        $this->assertSame(90.0, $r['igst_amount']);
        $this->assertSame(0.0, $r['cgst_amount']);
        $this->assertSame(0.0, $r['sgst_amount']);
    }

    // Test 6 — mixed cart, each line taxed independently by its own rate.
    public function test_mixed_cart_taxes_each_line_independently(): void
    {
        $this->business(); // inclusive, intra
        $plant = $this->product($this->taxRate(0), '0602', true);       // ₹300 @ 0%
        $fert  = $this->product($this->taxRate(5), '3101', true);       // ₹210 @ 5% → 10
        $pot   = $this->product($this->taxRate(18), '3924', true);      // ₹1180 @ 18% → 180
        $r = $this->gst()->compute([
            $this->line($plant, 300), $this->line($fert, 210), $this->line($pot, 1180),
        ], 0, $this->shipTo('Haryana'));

        $this->assertSame(190.0, $r['total_tax']);           // 0 + 10 + 180
        $lines = collect($r['lines']);
        $this->assertSame(0.0, $lines[0]['tax_amount']);
        $this->assertSame(10.0, $lines[1]['tax_amount']);
        $this->assertSame(180.0, $lines[2]['tax_amount']);
        // order tax == Σ line tax (rounding reconciliation)
        $this->assertSame($r['total_tax'], round($lines->sum('tax_amount'), 2));
    }

    // Test 8 — delivery follows principal supply (weighted rate), inclusive.
    public function test_delivery_follows_principal_supply(): void
    {
        $this->business(); // follow_principal, inclusive
        $pot = $this->product($this->taxRate(18), '3924', true);
        // single 18% line + ₹59 delivery → delivery taxed at 18% inclusive
        $r = $this->gst()->compute([$this->line($pot, 500)], 59, $this->shipTo('Haryana'));

        $this->assertGreaterThan(0, $r['delivery_tax_amount']);
        // 59 inclusive @18% → taxable 50.00, tax 9.00
        $this->assertSame(50.0, $r['delivery_taxable']);
        $this->assertSame(9.0, $r['delivery_tax_amount']);
        $this->assertSame('follow_principal', $r['delivery_tax_treatment']);
    }

    // Delivery on a zero-rated cart carries zero delivery GST (principal = 0%).
    public function test_delivery_zero_when_goods_zero_rated(): void
    {
        $this->business();
        $plant = $this->product($this->taxRate(0), '0602', true);
        $r = $this->gst()->compute([$this->line($plant, 300)], 59, $this->shipTo('Haryana'));
        $this->assertSame(0.0, $r['delivery_tax_amount']);
    }

    // Test 9 — free delivery ⇒ no delivery tax.
    public function test_free_delivery_no_tax(): void
    {
        $this->business();
        $pot = $this->product($this->taxRate(18), '3924', true);
        $r = $this->gst()->compute([$this->line($pot, 500)], 0, $this->shipTo('Haryana'));
        $this->assertSame(0.0, $r['delivery_tax_amount']);
    }

    // Test 10 — historical immutability: a SNAPSHOT taken before a rate change
    // does not move when the tax config later changes.
    public function test_snapshot_is_immutable_across_rate_change(): void
    {
        $this->business();
        $rateId = $this->taxRate(5, 'taxable', '3101');
        $p = $this->product($rateId, '3101', true);

        $before = $this->gst()->compute([$this->line($p, 210)], 0, $this->shipTo('Haryana'));
        $snapshotTax = $before['total_tax']; // 10.00, persisted with the order

        // Admin changes the product's GST to 18% tomorrow.
        \Illuminate\Support\Facades\DB::table('tax_classes')->where('id', $rateId)->update(['rate' => 18]);

        $after = $this->gst()->compute([$this->line($p, 210)], 0, $this->shipTo('Haryana'));

        $this->assertSame(10.0, $snapshotTax);                 // the stored figure never moves
        $this->assertNotSame($snapshotTax, $after['total_tax']); // a fresh compute reflects 18%
    }

    // Test 11 — the engine never reads any client-sent tax: only server config.
    public function test_engine_ignores_client_sent_tax(): void
    {
        $this->business();
        $p = $this->product($this->taxRate(18), '3924', true);
        $line = $this->line($p, 1180);
        $line['tax_amount'] = 0;   // attacker claims zero tax
        $line['gst_rate'] = 0;
        $r = $this->gst()->compute([$line], 0, $this->shipTo('Haryana'));
        $this->assertSame(180.0, $r['total_tax']); // recomputed from config, not the client
    }

    // Unconfigured product ⇒ 0% (never a blind default).
    public function test_unconfigured_product_is_zero_not_defaulted(): void
    {
        $this->business();
        $p = $this->product(null); // no tax_rate_id
        $r = $this->gst()->compute([$this->line($p, 999)], 0, $this->shipTo('Haryana'));
        $this->assertSame(0.0, $r['total_tax']);
        $this->assertSame('non_taxable', $r['lines'][0]['tax_category']);
    }

    // Inactive/expired tax config ⇒ treated as 0% until active.
    public function test_inactive_config_is_zero(): void
    {
        $this->business();
        $rateId = $this->taxRate(18, 'taxable', '3924', ['is_active' => 0]);
        $p = $this->product($rateId, '3924', true);
        $r = $this->gst()->compute([$this->line($p, 1180)], 0, $this->shipTo('Haryana'));
        $this->assertSame(0.0, $r['total_tax']);
    }

    // Test 12 — cancellation/refund tax reversal sums the IMMUTABLE line snapshot,
    // never a re-derived blended rate. Two lines (₹180 + ₹10 GST) → reverse both.
    public function test_reversal_sums_snapshot_tax(): void
    {
        $items = [
            ['tax_amount' => 180, 'cgst_amount' => 90, 'sgst_amount' => 90, 'igst_amount' => 0, 'taxable_value' => 1000],
            ['tax_amount' => 10,  'cgst_amount' => 5,  'sgst_amount' => 5,  'igst_amount' => 0, 'taxable_value' => 200],
        ];
        $r = GstService::sumSnapshotTax($items);
        $this->assertSame(190.0, $r['tax']);
        $this->assertSame(95.0, $r['cgst']);
        $this->assertSame(95.0, $r['sgst']);
        $this->assertSame(0.0, $r['igst']);
        $this->assertSame(1200.0, $r['taxable']);

        // Reversing a single cancelled line reverses only that line's snapshot.
        $one = GstService::sumSnapshotTax([$items[1]]);
        $this->assertSame(10.0, $one['tax']);
    }

    // Inter-state reversal keeps IGST (never splits it into CGST/SGST).
    public function test_reversal_inter_state_igst(): void
    {
        $r = GstService::sumSnapshotTax([
            ['tax_amount' => 90, 'cgst_amount' => 0, 'sgst_amount' => 0, 'igst_amount' => 90, 'taxable_value' => 500],
        ]);
        $this->assertSame(90.0, $r['igst']);
        $this->assertSame(0.0, $r['cgst']);
        $this->assertSame(0.0, $r['sgst']);
    }
}
