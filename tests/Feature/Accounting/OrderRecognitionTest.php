<?php

namespace Tests\Feature\Accounting;

use Marvel\Database\Models\Accounting\JournalEntry;
use Marvel\Database\Models\Accounting\JournalLine;
use Marvel\Database\Models\OrderItem;
use Marvel\Services\Accounting\AccountingPostingService;
use Marvel\Services\Accounting\FinancialReports;

class OrderRecognitionTest extends OrdersTestCase
{
    private function svc(): AccountingPostingService
    {
        return AccountingPostingService::make();
    }

    // 7 — a completed multi-vendor order posts the exact S68 journal (principal, inclusive, intra).
    public function test_completed_order_posts_correct_accounting(): void
    {
        $order = $this->s68Order();
        $je = $this->svc()->recognizeOrder($order, 'test');

        $this->assertNotNull($je);
        $this->assertSame(JournalEntry::POSTED, $je->status);
        $by = $this->byAccount($je);
        $this->assertSame('1060.00', $by['2070']['debit']);   // customer advance consumed
        $this->assertSame('914.21', $by['4010']['credit']);   // product sales (taxable)
        $this->assertSame('45.48', $by['2020']['credit']);    // CGST incl. delivery share (38.14+4.76+2.58)
        $this->assertSame('45.46', $by['2030']['credit']);    // SGST (38.13+4.76+2.57)
        $this->assertArrayNotHasKey('2040', $by);             // intra-state: no IGST
        $this->assertSame('54.85', $by['4030']['credit']);    // delivery revenue
        $this->assertSame('800.00', $by['5020']['debit']);    // vendor settlement cost
        $this->assertSame('800.00', $by['2010']['credit']);   // vendor payables
        $this->assertArrayNotHasKey('6070', $by);             // no rounding residual
        $this->assertSame((string) $je->total_debit, (string) $je->total_credit);
        $this->assertSame('1860.00', (string) $je->total_debit);

        // per-vendor payable dimension: A 240+400, B 160
        $r = new FinancialReports();
        $this->assertSame('640.00', $r->accountBalance('2010', null, 11));
        $this->assertSame('160.00', $r->accountBalance('2010', null, 12));
        $this->assertTrue($r->trialBalance()['balanced']);

        // order + line snapshots frozen
        $order->refresh();
        $this->assertSame(AccountingPostingService::RECOGNIZED, $order->financial_status);
        $this->assertSame($je->id, (int) $order->recognition_journal_id);
        $pot = OrderItem::where('order_id', $order->id)->where('product_id', 102)->first();
        $this->assertSame('400.00', (string) $pot->vendor_payable_snapshot);
        $this->assertSame('cost_sheet', $pot->commission_mode_snapshot);
        $this->assertSame($je->id, (int) $pot->journal_entry_id);
        // tax lines carry the item + HSN dimensions (spec §18 traceability)
        $this->assertSame(1, JournalLine::where('journal_entry_id', $je->id)->where('order_item_id', $pot->id)->where('tax_kind', 'cgst')->where('hsn_code', '3924')->count());
    }

    // 8 — the same completion event twice (both seams, a retried job) creates ONE journal.
    public function test_duplicate_order_event_creates_no_duplicate_accounting(): void
    {
        $order = $this->s68Order();
        $a = $this->svc()->recognizeOrder($order, 'test');
        $b = $this->svc()->recognizeOrder($order->fresh(), 'test');
        AccountingPostingService::onOrderStatusChanged($order->fresh(), 'order-out-for-delivery', 'order-completed'); // the seam hook
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, JournalEntry::where('source_type', 'ORDER_RECOGNIZED')->count());
        $this->assertSame('800.00', (new FinancialReports())->accountBalance('2010'));
    }

    // 9a — cancelled AFTER recognition: full de-recognition reversal, payables back to zero.
    public function test_cancel_after_recognition_reverses_everything(): void
    {
        $order = $this->s68Order();
        $this->svc()->recognizeOrder($order, 'test');
        AccountingPostingService::onOrderStatusChanged($order->fresh(), 'order-completed', 'order-cancelled');

        $r = new FinancialReports();
        $this->assertSame('0.00', $r->accountBalance('2010'));
        $this->assertSame('0.00', $r->accountBalance('4010'));
        $this->assertSame('0.00', $r->accountBalance('2020'));
        $this->assertSame(AccountingPostingService::DERECOGNIZED, $order->fresh()->financial_status);
        $this->assertSame(JournalEntry::REVERSED, JournalEntry::where('source_key', 'ORDER_RECOGNIZED:' . $order->id)->first()->status);
        $this->assertTrue($r->trialBalance()['balanced']);
        // a second cancel signal is a no-op
        AccountingPostingService::onOrderStatusChanged($order->fresh(), 'order-completed', 'order-cancelled');
        $this->assertSame(1, JournalEntry::where('source_type', 'REVERSAL')->count());
    }

    // 9b — cancelled BEFORE recognition with a capture: the advance becomes a refund payable.
    public function test_cancel_before_recognition_creates_refund_payable(): void
    {
        $order = $this->s68Order(['order_status' => 'order-processing']);
        $this->svc()->recordPaymentCaptured($order, 'razorpay', 'pay_ABC', 106000, 0, 0, ['order_id' => 'order_X', 'status' => 'captured']);
        AccountingPostingService::onOrderStatusChanged($order->fresh(), 'order-processing', 'order-cancelled');

        $r = new FinancialReports();
        $this->assertSame('0.00', $r->accountBalance('2070'));      // advance cleared
        $this->assertSame('1060.00', $r->accountBalance('2050'));   // refund owed to the customer
        $this->assertSame('1060.00', $r->accountBalance('1020'));   // money still sits with the gateway
        $this->assertSame(0, JournalEntry::where('source_type', 'ORDER_RECOGNIZED')->count());
        $this->assertTrue($r->trialBalance()['balanced']);
    }

    // an unassigned vendor line still recognises revenue/tax but flags the order for reconciliation.
    public function test_unassigned_line_recognises_revenue_but_flags(): void
    {
        $order = $this->s68Order([], false);
        $je = $this->svc()->recognizeOrder($order, 'test');
        $by = $this->byAccount($je);
        $this->assertSame('914.21', $by['4010']['credit']);
        $this->assertArrayNotHasKey('2010', $by);
        $this->assertSame(AccountingPostingService::REQUIRES_RECONCILIATION, $order->fresh()->financial_status);
        $this->assertTrue($je->requires_reconciliation);
    }

    // §54 — a paise residual inside tolerance posts to Rounding Differences and still balances.
    public function test_small_rounding_residual_posts_to_rounding_account(): void
    {
        $order = $this->s68Order(['paid_total' => 1060.02, 'total' => 1060.02]);
        $je = $this->svc()->recognizeOrder($order, 'test');
        $by = $this->byAccount($je);
        $this->assertSame(JournalEntry::POSTED, $je->status);
        $this->assertSame('0.02', $by['6070']['credit']);
        $this->assertSame((string) $je->total_debit, (string) $je->total_credit);
        $this->assertSame(AccountingPostingService::RECOGNIZED, $order->fresh()->financial_status);
    }

    // §54/§47 — a residual the snapshot cannot explain is DRAFTED (balanced) and flagged, never posted.
    public function test_large_residual_is_drafted_and_flagged(): void
    {
        $order = $this->s68Order(['paid_total' => 1061.00, 'total' => 1061.00]);
        $je = $this->svc()->recognizeOrder($order, 'test');
        $this->assertSame(JournalEntry::DRAFT, $je->status);
        $this->assertNull($je->entry_number);
        $this->assertSame((string) $je->total_debit, (string) $je->total_credit); // still balanced via 6070
        $this->assertSame(AccountingPostingService::REQUIRES_RECONCILIATION, $order->fresh()->financial_status);
        $this->assertSame('0.00', (new FinancialReports())->accountBalance('4010')); // nothing posted
    }

    // disabled switch ⇒ every hook is a no-op (safe deploy before cutover).
    public function test_disabled_accounting_is_noop(): void
    {
        \Illuminate\Support\Facades\DB::table('settings')->update(['options' => json_encode(['accounting' => ['enabled' => false]])]);
        $order = $this->s68Order();
        $this->assertNull(AccountingPostingService::make()->recognizeOrder($order, 'test'));
        AccountingPostingService::onOrderStatusChanged($order, 'order-processing', 'order-completed');
        $this->assertSame(0, JournalEntry::count());
        $this->assertNull($order->fresh()->financial_status);
    }

    // staging finding: a legacy flat tax class (settings.taxClass) charges an add-on the GST snapshot never sees —
    // it is still output tax the customer paid, so it posts (split by place of supply) instead of stranding as a draft.
    public function test_legacy_tax_class_addon_posts_as_output_tax(): void
    {
        \Illuminate\Support\Facades\Event::fake([\Marvel\Events\RefundRequested::class, \Marvel\Events\RefundUpdate::class]);
        $order = $this->s68Order(['sales_tax' => 12.19, 'paid_total' => 1072.19, 'total' => 1072.19]);
        AccountingPostingService::make()->recordPaymentCaptured($order, 'razorpay', 'pay_L', 107219, 0, 0, ['status' => 'captured']);
        $je = AccountingPostingService::make()->recognizeOrder($order->fresh(), 'test');
        $this->assertSame('posted', $je->status);
        $by = $this->byAccount($je);
        $this->assertSame(['1072.19', '51.58', '51.55'], [$by['2070']['debit'], $by['2020']['credit'], $by['2030']['credit']]); // 45.48+6.10, 45.46+6.09
        $this->assertArrayNotHasKey('6070', $by);
        $this->assertSame('recognized', $order->fresh()->financial_status);
        $this->assertStringContainsString('legacy tax-class add-on', json_encode($je->metadata));
        // a full refund takes it back out
        $refund = app(\Marvel\Database\Repositories\RefundRepository::class)->createSliced($order->fresh(), ['order_id' => $order->id, 'customer_id' => 5, 'title' => 'all'], 'full');
        $refund->forceFill(['status' => 'approved'])->saveQuietly();
        $rb = $this->byAccount(\Marvel\Services\Accounting\RefundService::make()->post($refund->fresh(), 'admin:1'));
        $this->assertSame(['1072.19', '51.58', '51.55'], [$rb['2050']['credit'], $rb['2020']['debit'], $rb['2030']['debit']]);
        $this->assertArrayNotHasKey('6070', $rb);
    }
}
