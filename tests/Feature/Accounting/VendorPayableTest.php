<?php

namespace Tests\Feature\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Marvel\Database\Models\OrderItem;
use Marvel\Database\Models\VendorLedgerEntry;
use Marvel\Events\RefundRequested;
use Marvel\Events\RefundUpdate;
use Marvel\Services\Accounting\AccountingPostingService;
use Marvel\Services\Accounting\FinancialReports;
use Marvel\Services\Accounting\ReconciliationEngine;
use Marvel\Services\Accounting\RefundService;
use Marvel\Services\Accounting\VendorCommissionRuleResolver;
use Marvel\Services\Accounting\VendorPayableCalculator;

class VendorPayableTest extends OrdersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        VendorCommissionRuleResolver::resetSchemaMemo();
        Event::fake([RefundRequested::class, RefundUpdate::class]);
    }

    private function rule(array $a): int
    {
        return DB::table('vendor_commission_rules')->insertGetId(array_merge(['scope' => 'vendor', 'mode' => 'percentage', 'value' => '10.0000', 'priority' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()], $a));
    }

    private function items($order): array
    {
        return OrderItem::where('order_id', $order->id)->orderBy('id')->get()->keyBy('product_id')->all();
    }

    // 13 — cost-sheet (D1) and legacy commission mode, vendor-funded discount, never negative.
    public function test_cost_sheet_and_commission_modes(): void
    {
        $order = $this->s68Order();
        $it = $this->items($order);
        $calc = new VendorPayableCalculator();
        $c = $calc->forItem($it[102]);                              // shop 11 default: cost sheet 400 × 1
        $this->assertSame(['cost_sheet', '400.00', '0.00', 'shop_default'], [$c['mode'], $c['payable']->toDecimal(), $c['commission']->toDecimal(), $c['scope']]);
        DB::table('balances')->insert(['shop_id' => 12, 'admin_commission_rate' => 20]);
        $c = $calc->forItem($it[103], 'commission');                // shop 12 commission via legacy rate: 190.48 − 20%
        $this->assertSame(['percentage', '20.0000', '38.10', '152.38', 'legacy'], [$c['mode'], $c['commission_rate'], $c['commission']->toDecimal(), $c['payable']->toDecimal(), $c['scope']]);
        $it[101]->forceFill(['discount_amount' => '50.00', 'discount_funded_by' => 'vendor'])->save();
        $this->assertSame('190.00', $calc->forItem($it[101]->fresh())['payable']->toDecimal()); // 240 − 50 vendor-funded
        $it[101]->forceFill(['discount_amount' => '300.00'])->save();
        $this->assertSame('0.00', $calc->forItem($it[101]->fresh())['payable']->toDecimal());   // floors at zero, never negative
        $this->assertSame('220.00', $calc->forItem($it[102], null, ['delivery' => \Marvel\Services\Accounting\MoneyBridge::toMoney('180.00')])['payable']->toDecimal());
    }

    // 14 — rule modes and precedence: product > category > vendor > shop mode; inactive/expired ignored; fixed per order allocated.
    public function test_rule_modes_and_precedence(): void
    {
        $order = $this->s68Order();
        $it = $this->items($order);
        $calc = new VendorPayableCalculator();
        $vendor = $this->rule(['shop_id' => 11, 'value' => '10.0000']);
        $this->assertSame(['270.00', 'vendor'], [$calc->forItem($it[101])['payable']->toDecimal(), $calc->forItem($it[101])['scope']]);   // 300 − 10%
        $this->assertSame('381.36', $calc->forItem($it[102])['payable']->toDecimal());                                                  // 423.73 − 42.37
        DB::table('category_product')->insert(['category_id' => 7, 'product_id' => 102]);
        $cat = $this->rule(['scope' => 'category', 'category_id' => 7, 'shop_id' => null, 'mode' => 'fixed_per_item', 'value' => '15.0000']);
        $c = $calc->forItem($it[102]);
        $this->assertSame(['fixed_per_item', '15.00', '408.73', 'category', $cat], [$c['mode'], $c['commission']->toDecimal(), $c['payable']->toDecimal(), $c['scope'], $c['rule_id']]);
        $prod = $this->rule(['scope' => 'product', 'product_id' => 102, 'shop_id' => null, 'value' => '25.0000']);
        $c = $calc->forItem($it[102]);
        $this->assertSame(['percentage', '105.93', '317.80', 'product', $prod], [$c['mode'], $c['commission']->toDecimal(), $c['payable']->toDecimal(), $c['scope'], $c['rule_id']]);
        DB::table('vendor_commission_rules')->where('id', $prod)->update(['is_active' => false]);
        $this->assertSame('category', $calc->forItem($it[102])['scope']);
        DB::table('vendor_commission_rules')->where('id', $cat)->update(['effective_to' => '2020-01-01']);
        $this->assertSame('vendor', $calc->forItem($it[102])['scope']);
        // fixed per ORDER: ₹50 for vendor 11 allocated across its two lines by base (300 : 423.73), largest remainder
        DB::table('vendor_commission_rules')->where('id', $vendor)->update(['mode' => 'fixed_per_order', 'value' => '50.0000']);
        $all = $calc->forLines(OrderItem::where('order_id', $order->id)->get());
        $this->assertSame('20.73', $all[$it[101]->id]['commission']->toDecimal());
        $this->assertSame('29.27', $all[$it[102]->id]['commission']->toDecimal());
        $this->assertSame('279.27', $all[$it[101]->id]['payable']->toDecimal());
        $this->assertSame('394.46', $all[$it[102]->id]['payable']->toDecimal());
        $this->assertSame('160.00', $all[$it[103]->id]['payable']->toDecimal()); // vendor 12 untouched (cost sheet)
        // a rule for another vendor never applies
        $this->rule(['shop_id' => 99, 'value' => '50.0000', 'priority' => 100]);
        $this->assertSame('160.00', $calc->forItem($it[103])['payable']->toDecimal());
    }

    // 21 — history is immune to rule changes: recognition freezes the rule; a later refund and the sub-ledger use the snapshot.
    public function test_historical_snapshot_is_immune_to_rule_changes(): void
    {
        $vendor = $this->rule(['shop_id' => 11, 'value' => '10.0000']);
        $order = $this->s68Order();
        AccountingPostingService::make()->recordPaymentCaptured($order, 'razorpay', 'pay_R', 106000, 0, 0, ['status' => 'captured']);
        AccountingPostingService::make()->recognizeOrder($order->fresh(), 'test');
        $it = $this->items($order);
        $this->assertSame(['percentage', '10.0000', '42.37', '381.36'], [$it[102]->commission_mode_snapshot, $it[102]->commission_rate_snapshot, $it[102]->commission_amount_snapshot, $it[102]->vendor_payable_snapshot]);
        $row = VendorLedgerEntry::where('order_item_id', $it[102]->id)->first();
        $this->assertSame(['mode' => 'percentage', 'rate' => '10.0000', 'rule_id' => $vendor, 'scope' => 'vendor'], $row->commission_rule_snapshot);
        $this->assertSame('651.36', (new FinancialReports())->accountBalance('2010', null, 11)); // 270 + 381.36

        DB::table('vendor_commission_rules')->where('id', $vendor)->update(['value' => '25.0000']);
        $this->assertSame('317.80', (new VendorPayableCalculator())->forItem($it[102]->fresh())['payable']->toDecimal()); // new orders would use 25%

        $refund = app(\Marvel\Database\Repositories\RefundRepository::class)->createSliced($order->fresh(), ['order_id' => $order->id, 'customer_id' => 5, 'title' => 'pot'], 'items', [['order_item_id' => $it[102]->id, 'quantity' => 1]], null, 'wallet');
        $refund->forceFill(['status' => 'approved'])->saveQuietly();
        $je = RefundService::make()->post($refund->fresh(), 'admin:1');
        $this->assertSame('381.36', $this->byAccount($je)['2010']['debit']); // the frozen 10% share, not 25%
        $this->assertSame('270.00', (new FinancialReports())->accountBalance('2010', null, 11));
        $this->assertSame([], (new ReconciliationEngine())->run(null, null, ['vendor'])['all_findings']);
    }
}
