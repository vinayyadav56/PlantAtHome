<?php

namespace Tests\Feature\Accounting;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Accounting\JournalEntry;
use Marvel\Database\Models\VendorLedgerEntry;
use Marvel\Services\Accounting\AccountingPostingService;
use Marvel\Services\Accounting\FinancialReports;
use Marvel\Services\Accounting\InventoryLedgerService;
use Marvel\Services\Accounting\ReconciliationEngine;
use Marvel\Services\Accounting\ReturnService;

class InventoryTest extends OrdersTestCase
{
    private function val(int $productId): array
    {
        $v = (new InventoryLedgerService())->valuation($productId);
        return [(int) $v->qty_on_hand, number_format((float) $v->avg_unit_cost, 4, '.', ''), number_format((float) $v->total_value, 2, '.', '')];
    }

    // 35-36 — receipts build a weighted-average valuation that always equals GL Inventory; idempotent.
    public function test_receipts_build_weighted_average_valuation(): void
    {
        $svc = new InventoryLedgerService();
        $je = $svc->receipt(102, 0, 0, 10, '250.0000', 'gr:1', null, 'first lot', 'admin:1');
        $by = $this->byAccount($je);
        $this->assertSame(['2500.00', '2500.00'], [$by['1040']['debit'], $by['2060']['credit']]);
        $this->assertSame([10, '250.0000', '2500.00'], $this->val(102));
        $je2 = $svc->receipt(102, 0, 0, 5, '310.0000', 'gr:2', 11, 'second lot from supplier 11', 'admin:1'); // 1550 → avg (2500+1550)/15 = 270
        $this->assertSame('1550.00', $this->byAccount($je2)['2010']['credit']);
        $this->assertSame([15, '270.0000', '4050.00'], $this->val(102));
        $this->assertSame($je->id, $svc->receipt(102, 0, 0, 10, '250.0000', 'gr:1', null, null, 'admin:1')->id); // replay
        $this->assertSame(2, DB::table('inventory_transactions')->count());
        $this->assertSame('4050.00', (new FinancialReports())->accountBalance('1040'));
        $this->assertSame([], (new ReconciliationEngine())->run(null, null, ['inventory'])['all_findings']);
    }

    // 37 — a PLATFORM_OWNED line recognises COGS at average cost inside the order's journal and carries no vendor payable.
    public function test_platform_owned_sale_posts_cogs_and_no_vendor_payable(): void
    {
        (new InventoryLedgerService())->receipt(102, 0, 0, 10, '250.0000', 'gr:1', null, null, 'admin:1');
        $order = $this->s68Order();
        $pot = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 102)->value('id');
        DB::table('order_items')->where('id', $pot)->update(['ownership_model' => 'PLATFORM_OWNED', 'assigned_shop_id' => null, 'vendor_price_snapshot' => null]);
        AccountingPostingService::make()->recordPaymentCaptured($order, 'razorpay', 'pay_I', 106000, 0, 0, ['status' => 'captured']);
        $je = AccountingPostingService::make()->recognizeOrder($order->fresh(), 'test');
        $by = $this->byAccount($je);
        $this->assertSame(['250.00', '250.00'], [$by['5010']['debit'], $by['1040']['credit']]);
        $this->assertSame('914.21', $by['4010']['credit']); // revenue unchanged (all three lines)
        $this->assertSame('400.00', $by['5020']['debit']);  // only A's plant (240) + B's fertilizer (160)
        $this->assertSame('240.00', (new FinancialReports())->accountBalance('2010', null, 11));
        $this->assertSame(0, VendorLedgerEntry::where('order_item_id', $pot)->count());
        $this->assertSame([9, '250.0000', '2250.00'], $this->val(102));
        $tx = DB::table('inventory_transactions')->where('idempotency_key', 'sale:' . $pot)->first();
        $this->assertSame([-1, $je->id, 'order_item'], [(int) $tx->quantity, (int) $tx->journal_entry_id, $tx->reference_type]);
        $this->assertSame('recognized', $order->fresh()->financial_status);
        $this->assertSame([], (new ReconciliationEngine())->run(null, null, ['inventory', 'vendor'])['all_findings']);
    }

    // 38 — a received return restocks at the SALE's unit cost (DR Inventory / CR COGS), once.
    public function test_return_received_restocks_at_sale_cost(): void
    {
        $svc = new InventoryLedgerService();
        $svc->receipt(102, 0, 0, 10, '250.0000', 'gr:1', null, null, 'admin:1');
        $order = $this->s68Order();
        $pot = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 102)->value('id');
        DB::table('order_items')->where('id', $pot)->update(['ownership_model' => 'PLATFORM_OWNED', 'assigned_shop_id' => null]);
        AccountingPostingService::make()->recordPaymentCaptured($order, 'razorpay', 'pay_R', 106000, 0, 0, ['status' => 'captured']);
        AccountingPostingService::make()->recognizeOrder($order->fresh(), 'test');
        $svc->receipt(102, 0, 0, 1, '350.0000', 'gr:2', null, null, 'admin:1'); // avg moves to 260 — the return must still use 250
        $rs = new ReturnService();
        $ret = $rs->request($pot, 1, 'damaged pot', 'customer:5');
        $rs->transition($ret->id, 'approve', 'admin:1');
        $rs->transition($ret->id, 'receive', 'admin:1');
        $je = JournalEntry::where('source_type', 'RETURN_RECEIVED')->first();
        $by = $this->byAccount($je);
        $this->assertSame(['250.00', '250.00'], [$by['1040']['debit'], $by['5010']['credit']]);
        $this->assertSame([11, '259.0909', '2850.00'], $this->val(102)); // 2250 + 350 + 250 = 2850 / 11
        $rs->transition($ret->id, 'receive', 'admin:1'); // idempotent
        $this->assertSame(1, JournalEntry::where('source_type', 'RETURN_RECEIVED')->count());
        $this->assertSame([], (new ReconciliationEngine())->run(null, null, ['inventory'])['all_findings']);
        // a vendor-supplied return never touches inventory
        $fert = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 103)->value('id');
        $r2 = $rs->request($fert, 1, 'x', 'customer:5');
        $rs->transition($r2->id, 'approve', 'admin:1');
        $rs->transition($r2->id, 'receive', 'admin:1');
        $this->assertSame(1, JournalEntry::where('source_type', 'RETURN_RECEIVED')->count());
    }

    // 39 — adjustments and write-downs move at average cost; a sale beyond valued stock is flagged, never silent.
    public function test_adjustments_and_short_stock(): void
    {
        $svc = new InventoryLedgerService();
        $svc->receipt(102, 0, 0, 4, '100.0000', 'gr:1', null, null, 'admin:1');
        $up = $svc->adjust(102, 0, 0, 2, 'adj:1', 'count correction', 'admin:1');
        $this->assertSame(['200.00', '200.00'], [$this->byAccount($up)['1040']['debit'], $this->byAccount($up)['6060']['credit']]);
        $down = $svc->adjust(102, 0, 0, -3, 'adj:2', 'damaged', 'admin:1');
        $this->assertSame(['300.00', '300.00'], [$this->byAccount($down)['6060']['debit'], $this->byAccount($down)['1040']['credit']]);
        $this->assertSame([3, '100.0000', '300.00'], $this->val(102));
        $this->assertSame('damage', DB::table('inventory_transactions')->where('idempotency_key', 'adj:2')->value('type'));
        $this->assertSame('300.00', (new FinancialReports())->accountBalance('1040'));
        // sell 1 with only 3 valued: fine; then a second platform-owned order for 5 → flagged
        $order = $this->s68Order();
        $pot = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 102)->value('id');
        DB::table('order_items')->where('id', $pot)->update(['ownership_model' => 'PLATFORM_OWNED', 'assigned_shop_id' => null, 'order_quantity' => 5, 'subtotal' => 2500, 'taxable_value' => '2118.64', 'cgst_amount' => '190.68', 'sgst_amount' => '190.68', 'tax_amount' => '381.36']);
        DB::table('orders')->where('id', $order->id)->update(['paid_total' => 3060, 'total' => 3060, 'taxable_amount' => '2609.12', 'cgst_amount' => '198.02', 'sgst_amount' => '198.01', 'total_tax' => '396.03']);
        AccountingPostingService::make()->recordPaymentCaptured($order->fresh(), 'razorpay', 'pay_S', 306000, 0, 0, ['status' => 'captured']);
        $je = AccountingPostingService::make()->recognizeOrder($order->fresh(), 'test');
        $this->assertSame('500.00', $this->byAccount($je)['5010']['debit']); // 5 × avg 100 — the ledger still posts, and says so
        $this->assertSame([0, '0.0000', '0.00'], $this->val(102));
        $this->assertStringContainsString('beyond valued stock', json_encode($je->metadata));
        $this->assertSame(1, DB::table('inventory_transactions')->where('type', 'sale')->count());
    }
}
