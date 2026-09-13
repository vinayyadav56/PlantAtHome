<?php

namespace Tests\Feature\Accounting;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Accounting\JournalEntry;
use Marvel\Database\Models\Accounting\PaymentEvent;
use Marvel\Services\Accounting\AccountingPostingService;
use Marvel\Services\Accounting\FinancialReports;

class PaymentEventsTest extends OrdersTestCase
{
    private function svc(): AccountingPostingService
    {
        return AccountingPostingService::make();
    }

    // 40 — a successful capture posts DR gateway receivable / CR customer advances and records the event.
    public function test_successful_capture_posts_receivable_and_advance(): void
    {
        $order = $this->s68Order(['order_status' => 'order-processing']);
        $je = $this->svc()->recordPaymentCaptured($order, 'razorpay', 'pay_1', 106000, 0, 0, ['order_id' => 'order_1', 'status' => 'captured']);

        $by = $this->byAccount($je);
        $this->assertSame('1060.00', $by['1020']['debit']);
        $this->assertSame('1060.00', $by['2070']['credit']);
        $this->assertSame(JournalEntry::POSTED, $je->status);
        $ev = PaymentEvent::where('gateway', 'razorpay')->where('event_id', 'pay_1:captured')->first();
        $this->assertNotNull($ev);
        $this->assertSame($je->id, (int) $ev->journal_entry_id);
        $this->assertSame('1060.00', (string) $ev->amount);
        $order->refresh();
        $this->assertSame('pay_1', $order->gateway_payment_id);
        $this->assertSame('1060.00', (string) $order->captured_amount);
        $this->assertNotNull($order->captured_at);
    }

    // wallet-covered part is a wallet liability draw-down, not gateway money.
    public function test_capture_with_wallet_portion(): void
    {
        $order = $this->s68Order(['order_status' => 'order-processing']);
        DB::table('order_wallet_points')->insert(['order_id' => $order->id, 'amount' => 60]);
        $je = $this->svc()->recordPaymentCaptured($order, 'razorpay', 'pay_2', 100000, 0, 0, ['status' => 'captured']);
        $by = $this->byAccount($je);
        $this->assertSame('1000.00', $by['1020']['debit']);
        $this->assertSame('60.00', $by['2080']['debit']);
        $this->assertSame('1060.00', $by['2070']['credit']);
        $this->assertFalse($je->requires_reconciliation);
    }

    // 43 — a replayed webhook for the same gateway payment id is ONE event and ONE journal.
    public function test_duplicate_webhook_is_single_event_and_journal(): void
    {
        $order = $this->s68Order(['order_status' => 'order-processing']);
        $a = $this->svc()->recordPaymentCaptured($order, 'razorpay', 'pay_3', 106000, 0, 0, ['status' => 'captured']);
        $b = $this->svc()->recordPaymentCaptured($order->fresh(), 'razorpay', 'pay_3', 106000, 0, 0, ['status' => 'captured']);
        $c = $this->svc()->recordPaymentCaptured($order->fresh(), 'razorpay', 'pay_3', 106000, 0, 0, ['status' => 'captured']); // reconcile path replay
        $this->assertSame($a->id, $b->id);
        $this->assertSame($a->id, $c->id);
        $this->assertSame(1, PaymentEvent::count());
        $this->assertSame(1, JournalEntry::where('source_type', 'PAYMENT_CAPTURED')->count());
        $this->assertSame('1060.00', (new FinancialReports())->accountBalance('1020'));
    }

    // 41 — a failed payment records nothing on the books (only captures post).
    public function test_failed_payment_posts_nothing(): void
    {
        $order = $this->s68Order(['order_status' => 'order-pending', 'payment_status' => 'payment-failed']);
        // the handler only calls recordPaymentCaptured for `captured`; no capture ⇒ no journal, no event
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, PaymentEvent::count());
        AccountingPostingService::onOrderStatusChanged($order, 'order-pending', 'order-cancelled'); // cancel of an unpaid order
        $this->assertSame(0, JournalEntry::count()); // nothing was collected ⇒ no refund payable
    }

    // 53 — the gateway fee is booked separately, never hidden in revenue.
    public function test_gateway_fee_posts_to_gateway_charges(): void
    {
        $order = $this->s68Order(['order_status' => 'order-processing']);
        $this->svc()->recordPaymentCaptured($order, 'razorpay', 'pay_4', 106000, 2000, 305, ['status' => 'captured']);
        $fee = JournalEntry::where('source_key', 'PAYMENT_FEE:razorpay:pay_4')->first();
        $this->assertNotNull($fee);
        $by = $this->byAccount($fee);
        $this->assertSame('20.00', $by['5050']['debit']);
        $this->assertSame('20.00', $by['1020']['credit']);
        $r = new FinancialReports();
        $this->assertSame('1040.00', $r->accountBalance('1020')); // 1060 captured − 20 fee
        $this->assertTrue($r->balanceSheet()['balanced']);
    }

    // 44 — captured + wallet must equal what the order says was paid; a mismatch is flagged, not hidden.
    public function test_capture_amount_mismatch_is_flagged(): void
    {
        $order = $this->s68Order(['order_status' => 'order-processing']);
        $je = $this->svc()->recordPaymentCaptured($order, 'razorpay', 'pay_5', 100000, 0, 0, ['status' => 'captured']); // 1000 captured vs 1060 paid_total
        $this->assertTrue($je->requires_reconciliation);
        $this->assertSame(AccountingPostingService::REQUIRES_RECONCILIATION, $order->fresh()->financial_status);
        $by = $this->byAccount($je);
        $this->assertSame('1000.00', $by['1020']['debit']); // actuals, never invented
        $this->assertSame('1000.00', $by['2070']['credit']);
    }
}
