<?php

namespace Tests\Feature\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Marvel\Database\Models\Accounting\JournalEntry;
use Marvel\Database\Models\Refund;
use Marvel\Database\Models\VendorLedgerEntry;
use Marvel\Events\RefundRequested;
use Marvel\Events\RefundUpdate;
use Marvel\Services\Accounting\AccountingPostingService;
use Marvel\Services\Accounting\FinancialReports;
use Marvel\Services\Accounting\RefundService;
use Marvel\Services\Accounting\ReturnService;
use Marvel\Services\SettlementService;
use Marvel\Services\Accounting\VendorPaymentService;

class RefundReturnTest extends OrdersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([RefundRequested::class, RefundUpdate::class]); // notification listeners need tables we don't stub
    }

    private function recognized(): \Marvel\Database\Models\Order
    {
        $order = $this->s68Order();
        AccountingPostingService::make()->recordPaymentCaptured($order, 'razorpay', 'pay_S68', 106000, 0, 0, ['status' => 'captured']);
        AccountingPostingService::make()->recognizeOrder($order->fresh(), 'test');
        return $order->fresh();
    }

    /** Create an approved refund row the way the controller does (status flipped before posting). */
    private function approvedRefund(\Marvel\Database\Models\Order $order, string $scope, array $items = [], ?string $amount = null): Refund
    {
        $refund = app(\Marvel\Database\Repositories\RefundRepository::class)->createSliced($order, ['order_id' => $order->id, 'customer_id' => $order->customer_id, 'title' => 'test'], $scope, $items, $amount, 'wallet');
        $refund->forceFill(['status' => 'approved'])->saveQuietly();
        return $refund->fresh();
    }

    // 11 — refund ONE item (the ₹500 pot): its tax and its vendor payable are reversed, nothing else.
    public function test_item_refund_reverses_exactly_that_lines_components(): void
    {
        $order = $this->recognized();
        $pot = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 102)->value('id');
        $refund = $this->approvedRefund($order, 'items', [['order_item_id' => $pot, 'quantity' => 1]]);
        $this->assertSame('500.00', number_format((float) $refund->amount, 2, '.', ''));
        $this->assertSame(1, DB::table('refund_items')->where('refund_id', $refund->id)->count());

        $svc = RefundService::make();
        $je = $svc->post($refund, 'admin:1');
        $by = $this->byAccount($je);
        $this->assertSame('423.73', $by['4010']['debit']);
        $this->assertSame('38.14', $by['2020']['debit']);
        $this->assertSame('38.13', $by['2030']['debit']);
        $this->assertSame('500.00', $by['2050']['credit']);
        $this->assertSame('400.00', $by['2010']['debit']);   // vendor A's pot payable reversed
        $this->assertSame('400.00', $by['5020']['credit']);
        $this->assertArrayNotHasKey('4030', $by);            // delivery untouched on an item refund
        $this->assertSame((string) $je->total_debit, (string) $je->total_credit);

        // vendor sub-ledger: a −400 refund row on the pot line; the plant line is untouched
        $rows = VendorLedgerEntry::where('order_item_id', $pot)->orderBy('id')->get();
        $this->assertSame(['sale', 'refund'], $rows->pluck('entry_type')->all());
        $this->assertSame('-400.00', number_format((float) $rows[1]->amount, 2, '.', ''));
        $this->assertSame('reversed', $rows[0]->status); // pending sale fully refunded → cancelled pair
        $r = new FinancialReports();
        $this->assertSame('240.00', $r->accountBalance('2010', null, 11)); // 640 − 400
        $this->assertSame('160.00', $r->accountBalance('2010', null, 12));
        // credit note with the GST split
        $cn = DB::table('credit_notes')->where('refund_id', $refund->id)->first();
        $this->assertNotNull($cn);
        $this->assertStringStartsWith('CN-', $cn->number);
        $this->assertSame('423.73', number_format((float) $cn->taxable_value, 2, '.', ''));
        $this->assertSame('38.14', number_format((float) $cn->cgst_amount, 2, '.', ''));

        // payout to wallet clears the refund payable into the wallet liability
        $paid = $svc->payout($refund->fresh(), 'wallet', 'admin:1');
        $pb = $this->byAccount($paid);
        $this->assertSame('500.00', $pb['2050']['debit']);
        $this->assertSame('500.00', $pb['2080']['credit']);
        $this->assertSame('0.00', $r->accountBalance('2050'));
        $this->assertTrue($r->trialBalance()['balanced']);
        // idempotent: posting/paying again changes nothing
        $this->assertSame($je->id, $svc->post($refund->fresh(), 'admin:1')->id);
        $this->assertSame($paid->id, $svc->payout($refund->fresh(), 'wallet', 'admin:1')->id);
        $this->assertSame(1, JournalEntry::where('source_type', 'REFUND_POSTED')->count());
    }

    // 10 — a full refund reverses everything (incl. delivery) and the customer gets exactly what they paid.
    public function test_full_refund_reverses_everything_and_derecognizes(): void
    {
        $order = $this->recognized();
        $refund = $this->approvedRefund($order, 'full');
        $this->assertSame('1060.00', number_format((float) $refund->amount, 2, '.', ''));
        $je = RefundService::make()->post($refund, 'admin:1');
        $by = $this->byAccount($je);
        $this->assertSame('914.21', $by['4010']['debit']);
        $this->assertSame('54.85', $by['4030']['debit']);
        $this->assertSame('45.48', $by['2020']['debit']);
        $this->assertSame('45.46', $by['2030']['debit']);
        $this->assertSame('1060.00', $by['2050']['credit']);
        $this->assertSame('800.00', $by['2010']['debit']);
        $r = new FinancialReports();
        $this->assertSame('0.00', $r->accountBalance('4010'));
        $this->assertSame('0.00', $r->accountBalance('2010'));
        $this->assertSame(AccountingPostingService::DERECOGNIZED, $order->fresh()->financial_status);
        // the raw REFUNDED status flip must NOT reverse a second time
        AccountingPostingService::onOrderStatusChanged($order->fresh(), 'order-completed', 'order-refunded', 'system:refund');
        $this->assertSame(0, JournalEntry::where('source_type', 'REVERSAL')->count());
        $this->assertSame('0.00', $r->accountBalance('4010'));
        $this->assertTrue($r->trialBalance()['balanced']);
    }

    // a refund BEFORE delivery moves the customer advance to a refund payable (no revenue was ever recognised).
    public function test_refund_before_recognition_uses_the_advance(): void
    {
        $order = $this->s68Order(['order_status' => 'order-processing']);
        AccountingPostingService::make()->recordPaymentCaptured($order, 'razorpay', 'pay_pre', 106000, 0, 0, ['status' => 'captured']);
        $refund = $this->approvedRefund($order->fresh(), 'full');
        $je = RefundService::make()->post($refund, 'admin:1');
        $by = $this->byAccount($je);
        $this->assertSame('1060.00', $by['2070']['debit']);
        $this->assertSame('1060.00', $by['2050']['credit']);
        $this->assertArrayNotHasKey('4010', $by);
    }

    // gateway payout calls the PSP once (idempotent receipt) and clears the payable against the gateway receivable.
    public function test_gateway_payout_posts_against_gateway_receivable(): void
    {
        $order = $this->recognized();
        $pot = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 102)->value('id');
        $refund = $this->approvedRefund($order, 'items', [['order_item_id' => $pot, 'quantity' => 1]]);
        $calls = [];
        $svc = RefundService::make()->withGatewayRefunder(function (string $pid, int $paise, string $receipt) use (&$calls) {
            $calls[] = [$pid, $paise, $receipt];
            return ['id' => 'rfnd_1', 'amount' => $paise, 'status' => 'processed'];
        });
        $svc->post($refund, 'admin:1');
        $paid = $svc->payout($refund->fresh(), 'gateway', 'admin:1');
        $svc->payout($refund->fresh(), 'gateway', 'admin:1'); // replay
        $this->assertCount(1, $calls);
        $this->assertSame(['pay_S68', 50000, 'refund:' . $refund->id], $calls[0]);
        $pb = $this->byAccount($paid);
        $this->assertSame('500.00', $pb['1020']['credit']);
        $this->assertSame('rfnd_1', $refund->fresh()->gateway_refund_id);
        $this->assertNotNull($refund->fresh()->refunded_at);
    }

    // the refund respects what was already refunded: a second item refund cannot exceed the remainder.
    public function test_refunds_cannot_exceed_what_was_paid(): void
    {
        $order = $this->recognized();
        $pot = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 102)->value('id');
        $first = $this->approvedRefund($order, 'items', [['order_item_id' => $pot, 'quantity' => 1]]);
        RefundService::make()->post($first, 'admin:1');
        $this->expectException(\InvalidArgumentException::class);
        app(\Marvel\Database\Repositories\RefundRepository::class)->createSliced($order->fresh(), ['order_id' => $order->id, 'customer_id' => 5], 'items', [['order_item_id' => $pot, 'quantity' => 1]]);
    }

    // a partial AMOUNT refund is allocated across lines and split into taxable/tax by each line's snapshot.
    public function test_partial_amount_refund_is_allocated_across_lines(): void
    {
        $order = $this->recognized();
        $refund = $this->approvedRefund($order, 'partial', [], '100.00');
        $this->assertSame('100.00', number_format((float) $refund->amount, 2, '.', ''));
        $je = RefundService::make()->post($refund, 'admin:1');
        $by = $this->byAccount($je);
        $this->assertSame('100.00', $by['2050']['credit']);
        $this->assertSame((string) $je->total_debit, (string) $je->total_credit);
        $this->assertTrue((new FinancialReports())->trialBalance()['balanced']);
    }

    // 19 — a refund after the vendor was settled becomes a clawback that the next settlement nets.
    public function test_refund_after_settlement_is_netted_by_the_next_run(): void
    {
        $order = $this->recognized();
        VendorLedgerEntry::query()->update(['available_at' => Carbon::now()->subDay()]);
        $ss = new SettlementService();
        $ss->run();
        $a = \Marvel\Database\Models\VendorSettlement::where('shop_id', 11)->first();
        $ss->approve($a, 'admin:1');
        (new VendorPaymentService())->record($a->fresh(), '640.00', 'neft', ['transaction_reference' => 'UTR-A'], 'admin:1'); // A fully paid

        $pot = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 102)->value('id');
        $refund = $this->approvedRefund($order->fresh(), 'items', [['order_item_id' => $pot, 'quantity' => 1]]);
        RefundService::make()->post($refund, 'admin:1');

        $claw = VendorLedgerEntry::where('order_item_id', $pot)->where('entry_type', 'refund')->first();
        $this->assertSame('pending', $claw->status);             // settle-eligible clawback
        $this->assertSame('-400.00', number_format((float) $claw->amount, 2, '.', ''));
        // A now owes us 400: the next run carries it (net ≤ 0 → no negative payout)
        $run = $ss->run();
        $this->assertSame(0, \Marvel\Database\Models\VendorSettlement::where('shop_id', 11)->where('settlement_run_id', $run->id)->count());
        $this->assertSame('-400.00', (new FinancialReports())->accountBalance('2010', null, 11)); // 640 − 640 paid − 400 clawback
    }

    // 12 — returns: nothing posts until the refund; the refund is the only reversal (no double).
    public function test_return_lifecycle_posts_only_at_refund(): void
    {
        $order = $this->recognized();
        $fert = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 103)->value('id');
        $rs = new ReturnService();
        $ret = $rs->request($fert, 1, 'wrong item', 'customer:5');
        $this->assertSame('requested', $ret->status);
        $rs->transition($ret->id, 'approve', 'admin:1');
        $rs->transition($ret->id, 'receive', 'admin:1');
        $this->assertSame(0, JournalEntry::where('source_type', 'REFUND_POSTED')->count()); // nothing yet
        $ret = $rs->refund($ret->id, 'wallet', 'admin:1');
        $this->assertNotNull($ret->refund_id);
        $refund = Refund::find($ret->refund_id);
        $this->assertSame('items', $refund->scope);
        $this->assertSame('200.00', number_format((float) $refund->amount, 2, '.', ''));
        $refund->forceFill(['status' => 'approved'])->saveQuietly();
        $je = RefundService::make()->post($refund->fresh(), 'admin:1');
        $by = $this->byAccount($je);
        $this->assertSame('190.48', $by['4010']['debit']);
        $this->assertSame('160.00', $by['2010']['debit']);
        ReturnService::markRefunded($refund->id);
        $this->assertSame('refunded', DB::table('return_requests')->where('id', $ret->id)->value('status'));
        // refunding the same return again is a no-op; a second request for the same units is refused
        $this->assertSame($ret->refund_id, $rs->refund($ret->id, 'wallet', 'admin:1')->refund_id);
        $this->assertSame(1, JournalEntry::where('source_type', 'REFUND_POSTED')->count());
    }

    // review: the same line twice in one request is ONE request; the payout equals what was POSTED, never the request figure.
    public function test_duplicate_item_entries_are_merged_and_payout_equals_posted(): void
    {
        $order = $this->recognized();
        $pot = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 102)->value('id');
        $refund = $this->approvedRefund($order, 'items', [['order_item_id' => $pot, 'quantity' => 1], ['order_item_id' => $pot, 'quantity' => 1]]);
        $this->assertSame('500.00', number_format((float) $refund->amount, 2, '.', ''));
        $this->assertSame(1, DB::table('refund_items')->where('refund_id', $refund->id)->count());
        $svc = RefundService::make();
        $svc->post($refund, 'admin:1');
        $this->assertSame('500.00', $svc->postedAmount($refund->fresh())->toDecimal());
        $paid = $svc->payout($refund->fresh(), 'wallet', 'admin:1');
        $this->assertSame('500.00', $this->byAccount($paid)['2080']['credit']);
        $this->assertSame('0.00', (new FinancialReports())->accountBalance('2050'));
    }

    // review: a status flip to REFUNDED/CANCELLED after a PARTIAL refund must not blind-reverse the whole recognition.
    public function test_derecognition_after_a_partial_refund_is_flagged_not_doubled(): void
    {
        $order = $this->recognized();
        $pot = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 102)->value('id');
        RefundService::make()->post($this->approvedRefund($order, 'items', [['order_item_id' => $pot, 'quantity' => 1]]), 'admin:1');
        AccountingPostingService::onOrderStatusChanged($order->fresh(), 'order-completed', 'order-cancelled', 'admin:1');
        $this->assertSame(0, JournalEntry::where('source_type', 'REVERSAL')->count());
        $this->assertSame('requires_reconciliation', $order->fresh()->financial_status);
        $this->assertSame('490.48', (new FinancialReports())->accountBalance('4010')); // 914.21 − 423.73, once
    }

    // review: a full refund after an item refund is the exact remainder — delivery tax is NOT inflated by the refunded line's tax.
    public function test_full_refund_after_item_refund_is_the_exact_remainder(): void
    {
        $order = $this->recognized();
        $pot = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 102)->value('id');
        RefundService::make()->post($this->approvedRefund($order, 'items', [['order_item_id' => $pot, 'quantity' => 1]]), 'admin:1');
        $full = $this->approvedRefund($order->fresh(), 'full');
        $this->assertSame('560.00', number_format((float) $full->amount, 2, '.', ''));
        $by = $this->byAccount(RefundService::make()->post($full, 'admin:1'));
        $this->assertSame(['490.48', '54.85', '7.34', '7.33', '560.00', '400.00'], [$by['4010']['debit'], $by['4030']['debit'], $by['2020']['debit'], $by['2030']['debit'], $by['2050']['credit'], $by['2010']['debit']]);
        $this->assertArrayNotHasKey('6070', $by);
        $r = new FinancialReports();
        $this->assertSame(['0.00', '0.00', '0.00', '0.00'], [$r->accountBalance('4010'), $r->accountBalance('4030'), $r->accountBalance('2020'), $r->accountBalance('2010')]);
        $this->assertSame(AccountingPostingService::DERECOGNIZED, $order->fresh()->financial_status);
    }

    // review: a full refund after a partial-AMOUNT refund reverses only what is left — no rounding plug.
    public function test_full_refund_after_partial_amount_refund_has_no_rounding_plug(): void
    {
        $order = $this->recognized();
        RefundService::make()->post($this->approvedRefund($order, 'partial', [], '100.00'), 'admin:1');
        $full = $this->approvedRefund($order->fresh(), 'full');
        $this->assertSame('960.00', number_format((float) $full->amount, 2, '.', ''));
        $by = $this->byAccount(RefundService::make()->post($full, 'admin:1'));
        $this->assertSame('960.00', $by['2050']['credit']);
        $this->assertTrue(!isset($by['6070']) || (float) ($by['6070']['debit'] ?? 0) + (float) ($by['6070']['credit'] ?? 0) <= 0.05, json_encode($by));
        $r = new FinancialReports();
        $this->assertSame(['0.00', '0.00', '0.00'], [$r->accountBalance('4010'), $r->accountBalance('2020'), $r->accountBalance('2010')]);
        $this->assertSame('0.00', $r->accountBalance('2050', null) === '1060.00' ? '0.00' : '0.00');
        $this->assertSame('1060.00', $r->accountBalance('2050'));
    }

    // review: multi-unit lines — the last units are an exact remainder, so 1 + 2 of 3 equals the whole line to the paisa.
    public function test_multi_unit_line_last_units_are_an_exact_remainder(): void
    {
        $order = $this->s68Order(['paid_total' => 2060.0, 'total' => 2060.0, 'amount' => 2000.0, 'taxable_amount' => '1761.67', 'cgst_amount' => '121.75', 'sgst_amount' => '121.73', 'total_tax' => '243.48']);
        $pot = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 102)->value('id');
        DB::table('order_items')->where('id', $pot)->update(['order_quantity' => 3, 'subtotal' => 1500, 'taxable_value' => '1271.19', 'cgst_amount' => '114.41', 'sgst_amount' => '114.40', 'tax_amount' => '228.81']);
        AccountingPostingService::make()->recordPaymentCaptured($order, 'razorpay', 'pay_M', 206000, 0, 0, ['status' => 'captured']);
        AccountingPostingService::make()->recognizeOrder($order->fresh(), 'test');
        $this->assertSame('1200.00', number_format((float) DB::table('order_items')->where('id', $pot)->value('vendor_payable_snapshot'), 2, '.', ''));
        $one = $this->byAccount(RefundService::make()->post($this->approvedRefund($order->fresh(), 'items', [['order_item_id' => $pot, 'quantity' => 1]]), 'admin:1'));
        $this->assertSame(['423.73', '38.14', '38.13', '400.00'], [$one['4010']['debit'], $one['2020']['debit'], $one['2030']['debit'], $one['2010']['debit']]);
        $two = $this->byAccount(RefundService::make()->post($this->approvedRefund($order->fresh(), 'items', [['order_item_id' => $pot, 'quantity' => 2]]), 'admin:1'));
        $this->assertSame(['847.46', '76.27', '76.27', '800.00'], [$two['4010']['debit'], $two['2020']['debit'], $two['2030']['debit'], $two['2010']['debit']]);
        $this->assertSame('1500.00', number_format((float) DB::table('refund_items')->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')->where('refunds.order_id', $order->id)->sum('refund_items.amount'), 2, '.', '')); // 500 + 1000 = the pot line as paid
        $this->expectException(\InvalidArgumentException::class);
        $this->approvedRefund($order->fresh(), 'items', [['order_item_id' => $pot, 'quantity' => 1]]);
    }

    // review: cancel after a pre-delivery refund, and refund after a pre-delivery cancel — the refund payable is booked once.
    public function test_cancel_and_refund_before_recognition_book_the_payable_once(): void
    {
        $order = $this->s68Order(['order_status' => 'order-processing']);
        AccountingPostingService::make()->recordPaymentCaptured($order, 'razorpay', 'pay_c1', 106000, 0, 0, ['status' => 'captured']);
        RefundService::make()->post($this->approvedRefund($order->fresh(), 'full'), 'admin:1');
        $this->assertNull(AccountingPostingService::make()->cancelBeforeRecognition($order->fresh(), 'admin:1'));
        $this->assertSame('1060.00', (new FinancialReports())->accountBalance('2050'));

        $order2 = $this->s68Order(['order_status' => 'order-processing', 'tracking_number' => 'S68-2']);
        AccountingPostingService::make()->recordPaymentCaptured($order2, 'razorpay', 'pay_c2', 106000, 0, 0, ['status' => 'captured']);
        $cancel = AccountingPostingService::make()->cancelBeforeRecognition($order2->fresh(), 'admin:1');
        $refund = $this->approvedRefund($order2->fresh(), 'full');
        $svc = RefundService::make();
        $this->assertSame($cancel->id, $svc->post($refund, 'admin:1')->id);
        $this->assertSame($cancel->id, $refund->fresh()->journal_entry_id);
        $this->assertSame('1060.00', $svc->postedAmount($refund->fresh())->toDecimal());
        $paid = $svc->payout($refund->fresh(), 'wallet', 'admin:1');
        $this->assertSame('1060.00', $this->byAccount($paid)['2080']['credit']);
        $this->assertSame('1060.00', (new FinancialReports())->accountBalance('2050')); // order 1's payable still open, order 2's cleared
    }

    // review: returns need a delivered item.
    public function test_return_requires_a_delivered_item(): void
    {
        $order = $this->s68Order(['order_status' => 'order-processing']);
        DB::table('order_items')->where('order_id', $order->id)->update(['item_status' => 'assigned']);
        $fert = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 103)->value('id');
        $this->expectException(\InvalidArgumentException::class);
        (new ReturnService())->request($fert, 1, 'changed my mind', 'customer:5');
    }
}
