<?php

namespace Tests\Feature\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Accounting\JournalEntry;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Models\VendorLedgerEntry;
use Marvel\Database\Models\VendorSettlement;
use Marvel\Services\Accounting\AccountingConfig;
use Marvel\Services\Accounting\AccountingPostingService;
use Marvel\Services\Accounting\FinancialReports;
use Marvel\Services\Accounting\JournalService;
use Marvel\Services\Accounting\ReconciliationEngine;
use Marvel\Services\Accounting\VendorPayableCalculator;
use Marvel\Services\SettlementService;

class DeliveryAccountingTest extends OrdersTestCase
{
    private function svc(array $delivery = []): AccountingPostingService
    {
        return new AccountingPostingService(new AccountingConfig(['accounting' => ['enabled' => true, 'delivery' => $delivery]]), new JournalService(), new VendorPayableCalculator());
    }

    private function shipment($order, array $a = []): Shipment
    {
        return Shipment::create(array_merge(['order_id' => $order->id, 'shop_id' => 11, 'status' => 'delivered', 'shipping_cost' => '80.00', 'delivered_at' => Carbon::now()], $a));
    }

    // 30-31 — courier cost → courier payable; DP cost → DP payables; nothing at zero cost; idempotent.
    public function test_shipment_cost_posts_to_the_right_payable(): void
    {
        $order = $this->s68Order();
        $je = $this->svc()->recordShipmentCost($this->shipment($order), 'test');
        $by = $this->byAccount($je);
        $this->assertSame(['80.00', '80.00'], [$by['5030']['debit'], $by['2060']['credit']]);
        $this->assertArrayNotHasKey('2010', $by);
        $dp = $this->svc()->recordShipmentCost($this->shipment($order, ['delivery_partner_id' => 7, 'shipping_cost' => '45.00']), 'test');
        $this->assertSame('45.00', $this->byAccount($dp)['2090']['credit']);
        $this->assertNull($this->svc()->recordShipmentCost($this->shipment($order, ['shipping_cost' => '0.00'])));
        $this->assertSame($je->id, $this->svc()->recordShipmentCost(Shipment::find($je->source_id), 'test')->id);
        $this->assertSame(2, JournalEntry::where('source_type', 'SHIPMENT_COST')->count());
        $this->assertSame(1, DB::table('order_events')->where('type', 'accounting.shipment_cost')->where('order_id', $order->id)->count() >= 1 ? 1 : 0);
    }

    // 32 — the configured vendor share is recovered from the vendor and nets in the next settlement.
    public function test_vendor_share_is_deducted_and_netted_by_settlement(): void
    {
        $order = $this->s68Order();
        AccountingPostingService::make()->recordPaymentCaptured($order, 'razorpay', 'pay_D', 106000, 0, 0, ['status' => 'captured']);
        AccountingPostingService::make()->recognizeOrder($order->fresh(), 'test');
        $je = $this->svc(['vendor_share_percent' => 50, 'per_shop' => [12 => 0]])->recordShipmentCost($this->shipment($order), 'test');
        $by = $this->byAccount($je);
        $this->assertSame(['80.00', '40.00', '80.00', '40.00'], [$by['5030']['debit'], $by['5030']['credit'], $by['2060']['credit'], $by['2010']['debit']]);
        $row = VendorLedgerEntry::where('entry_type', 'delivery_deduction')->first();
        $this->assertSame(['-40.00', 11, 'pending'], [number_format((float) $row->amount, 2, '.', ''), (int) $row->shop_id, $row->status]);
        $this->assertSame($je->id, $row->journal_entry_id);
        $this->assertSame('600.00', (new FinancialReports())->accountBalance('2010', null, 11)); // 640 − 40
        $this->assertSame([], (new ReconciliationEngine())->run(null, null, ['vendor'])['all_findings']);
        // a per-shop override of 0% for shop 12 → no share
        $this->assertArrayNotHasKey('2010', $this->byAccount($this->svc(['vendor_share_percent' => 50, 'per_shop' => [12 => 0]])->recordShipmentCost($this->shipment($order, ['shop_id' => 12]), 'test')));
        VendorLedgerEntry::query()->update(['available_at' => Carbon::now()->subDay()]);
        (new SettlementService())->run();
        $s = VendorSettlement::where('shop_id', 11)->first();
        $this->assertSame('600.00', number_format((float) $s->total_payable, 2, '.', ''));
        $this->assertSame('-40.00', number_format((float) $s->deductions, 2, '.', '')); // signed, like every sub-ledger figure
        $this->assertSame('settled', $row->fresh()->status);
    }

    // 33 — no delivery charge → no delivery revenue line, totals still tie.
    public function test_free_delivery_recognises_no_delivery_revenue(): void
    {
        $order = $this->s68Order(['delivery_fee' => 0.0, 'paid_total' => 1000.0, 'total' => 1000.0, 'delivery_taxable' => '0.00', 'delivery_tax_amount' => '0.00', 'cgst_amount' => '42.90', 'sgst_amount' => '42.89', 'total_tax' => '85.79']);
        AccountingPostingService::make()->recordPaymentCaptured($order, 'razorpay', 'pay_F', 100000, 0, 0, ['status' => 'captured']);
        $je = AccountingPostingService::make()->recognizeOrder($order->fresh(), 'test');
        $by = $this->byAccount($je);
        $this->assertArrayNotHasKey('4030', $by);
        $this->assertSame('1000.00', $by['2070']['debit']);
        $this->assertSame((string) $je->total_debit, (string) $je->total_credit);
        $this->assertArrayNotHasKey('6070', $by);
    }

    // 34 — the courier seam: applyNormalizedStatus-style delivered save triggers exactly one posting.
    public function test_delivered_seam_posts_once(): void
    {
        $order = $this->s68Order();
        $s = $this->shipment($order, ['status' => 'in_transit', 'delivered_at' => null]);
        $s->forceFill(['status' => 'delivered', 'delivered_at' => Carbon::now()])->save();
        AccountingPostingService::onShipmentDelivered($s);
        AccountingPostingService::onShipmentDelivered($s->fresh());
        $this->assertSame(1, JournalEntry::where('source_key', 'SHIPMENT_COST:' . $s->id)->count());
    }
}
