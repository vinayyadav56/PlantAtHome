<?php

namespace Tests\Feature\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Marvel\Events\RefundRequested;
use Marvel\Events\RefundUpdate;
use Marvel\Exports\TaxReportExport;
use Marvel\Services\Accounting\AccountingPostingService;
use Marvel\Services\Accounting\FinancialReports;
use Marvel\Services\Accounting\RefundService;

class GstLedgerTest extends OrdersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([RefundRequested::class, RefundUpdate::class]);
    }

    private function recognize($order): void
    {
        AccountingPostingService::make()->recordPaymentCaptured($order, 'razorpay', 'pay_' . $order->id, (int) round($order->paid_total * 100), 0, 0, ['status' => 'captured']);
        AccountingPostingService::make()->recognizeOrder($order->fresh(), 'test');
    }

    private function row(array $ledger, ?string $hsn): ?array
    {
        foreach ($ledger['rows'] as $r) {
            if ($r['hsn_code'] === $hsn) {
                return $r;
            }
        }
        return null;
    }

    // 22-26 — intra-state mixed cart (0% / 18% / 5%) + delivery: CGST+SGST by HSN, no IGST; totals tie to the GL.
    public function test_intra_state_tax_ledger_by_hsn(): void
    {
        $this->recognize($this->s68Order());
        $l = (new FinancialReports())->taxLedger();
        $this->assertSame(['cgst' => '45.48', 'sgst' => '45.46', 'igst' => '0.00', 'output_tax' => '90.94'], array_intersect_key($l['totals'], array_flip(['cgst', 'sgst', 'igst', 'output_tax'])));
        $this->assertSame('969.06', $l['totals']['taxable']); // 914.21 + 54.85 delivery
        $pot = $this->row($l, '3924');
        $this->assertSame(['423.73', '38.14', '38.13', '0.00', '18.00'], [$pot['taxable'], $pot['cgst'], $pot['sgst'], $pot['igst'], $pot['tax_rate']]);
        $this->assertSame(['190.48', '4.76', '4.76'], array_values(array_intersect_key($this->row($l, '3101'), array_flip(['taxable', 'cgst', 'sgst']))));
        $this->assertSame(['300.00', '0.00', '0.00'], array_values(array_intersect_key($this->row($l, '0602'), array_flip(['taxable', 'cgst', 'sgst']))));
        $this->assertSame(['54.85', '2.58', '2.57'], array_values(array_intersect_key($this->row($l, null), array_flip(['taxable', 'cgst', 'sgst'])))); // delivery
        $this->assertSame($l['totals']['cgst'], $l['gl_balances']['cgst']);
        $this->assertSame($l['totals']['sgst'], $l['gl_balances']['sgst']);
    }

    // 27 — inter-state: IGST only, never CGST/SGST.
    public function test_inter_state_posts_igst_only(): void
    {
        $order = $this->s68Order(['is_inter_state' => true, 'cgst_amount' => '0.00', 'sgst_amount' => '0.00', 'igst_amount' => '90.94', 'shipping_address' => ['state' => 'Delhi']]);
        foreach (DB::table('order_items')->where('order_id', $order->id)->get() as $it) {
            DB::table('order_items')->where('id', $it->id)->update(['igst_amount' => $it->tax_amount, 'cgst_amount' => '0.00', 'sgst_amount' => '0.00']);
        }
        $this->recognize($order->fresh());
        $l = (new FinancialReports())->taxLedger();
        $this->assertSame(['cgst' => '0.00', 'sgst' => '0.00', 'igst' => '90.94'], array_intersect_key($l['totals'], array_flip(['cgst', 'sgst', 'igst'])));
        $this->assertSame('76.27', $this->row($l, '3924')['igst']);
        $this->assertSame('5.15', $this->row($l, null)['igst']);
        $this->assertSame('90.94', (new FinancialReports())->accountBalance('2040'));
        $this->assertSame('0.00', (new FinancialReports())->accountBalance('2020'));
    }

    // 28-29 — a refund nets the ledger by HSN and appears as a NEGATIVE credit-note row in the GSTR export.
    public function test_credit_note_nets_the_ledger_and_the_export(): void
    {
        $order = $this->s68Order();
        $this->recognize($order);
        $pot = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 102)->value('id');
        $refund = app(\Marvel\Database\Repositories\RefundRepository::class)->createSliced($order->fresh(), ['order_id' => $order->id, 'customer_id' => 5, 'title' => 'pot'], 'items', [['order_item_id' => $pot, 'quantity' => 1]], null, 'wallet');
        $refund->forceFill(['status' => 'approved'])->saveQuietly();
        RefundService::make()->post($refund->fresh(), 'admin:1');

        $l = (new FinancialReports())->taxLedger();
        $this->assertSame(['0.00', '0.00', '0.00'], array_values(array_intersect_key($this->row($l, '3924'), array_flip(['taxable', 'cgst', 'sgst'])))); // fully netted
        $this->assertSame(['cgst' => '7.34', 'sgst' => '7.33'], array_intersect_key($l['totals'], array_flip(['cgst', 'sgst'])));
        $this->assertSame('545.33', $l['totals']['taxable']); // 969.06 − 423.73

        $rows = (new TaxReportExport([]))->collection()->all();
        $cn = array_values(array_filter($rows, fn ($r) => str_starts_with($r['invoice_no'], 'CN-')));
        $this->assertCount(1, $cn);
        $this->assertSame(['3924', '-423.73', '-38.14', '-38.13', '0.00', -1, '18%'], [$cn[0]['hsn'], $cn[0]['taxable'], $cn[0]['cgst'], $cn[0]['sgst'], $cn[0]['igst'], $cn[0]['qty'], $cn[0]['gst_rate']]);
        $this->assertStringContainsString('S68-1', $cn[0]['customer']);
        // the invoice rows are untouched (history immutable); net taxable across the export = ledger
        $net = array_sum(array_map(fn ($r) => (float) $r['taxable'], $rows));
        $this->assertSame('545.33', number_format($net, 2, '.', ''));
    }
}
