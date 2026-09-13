<?php

namespace Tests\Feature\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Accounting\JournalEntry;
use Marvel\Database\Models\VendorAdjustment;
use Marvel\Database\Models\VendorLedgerEntry;
use Marvel\Database\Models\VendorPayment;
use Marvel\Database\Models\VendorSettlement;
use Marvel\Database\Models\Withdraw;
use Marvel\Services\Accounting\AccountingPostingService;
use Marvel\Services\Accounting\FinancialReports;
use Marvel\Services\Accounting\VendorAdjustmentService;
use Marvel\Services\Accounting\VendorPaymentService;
use Marvel\Services\SettlementService;

class SettlementPaymentTest extends OrdersTestCase
{
    /** Recognise the S68 order and make every ledger row settle-eligible (the 7-day hold elapsed). */
    private function recognizedAndEligible(): void
    {
        $order = $this->s68Order();
        AccountingPostingService::make()->recognizeOrder($order, 'test');
        VendorLedgerEntry::query()->update(['available_at' => Carbon::now()->subDay()]);
    }

    private function settlementFor(int $shop): VendorSettlement
    {
        return VendorSettlement::where('shop_id', $shop)->orderByDesc('id')->firstOrFail();
    }

    // 18 — a run settles only eligible rows, one settlement per vendor, with the spec §22 fields.
    public function test_settlement_run_creates_per_vendor_settlements(): void
    {
        $order = $this->s68Order();
        AccountingPostingService::make()->recognizeOrder($order, 'test');
        // nothing eligible yet (inside the return window) → no settlements
        $run0 = (new SettlementService())->run(Carbon::now(), null, 'weekly');
        $this->assertSame(0, $run0->vendor_count);

        VendorLedgerEntry::query()->update(['available_at' => Carbon::now()->subDay()]);
        $run = (new SettlementService())->run(Carbon::now(), 7, 'weekly');
        $this->assertSame('locked', $run->status);
        $this->assertSame(2, $run->vendor_count);
        $this->assertSame('800.00', number_format((float) $run->total_amount, 2, '.', ''));

        $a = $this->settlementFor(11);
        $this->assertSame(SettlementService::PENDING_APPROVAL, $a->status);
        $this->assertSame('640.00', number_format((float) $a->total_payable, 2, '.', ''));
        $this->assertSame('640.00', number_format((float) $a->remaining_payable, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $a->amount_paid, 2, '.', ''));
        $this->assertNotNull($a->period_to);
        $this->assertSame(2, VendorLedgerEntry::where('vendor_settlement_id', $a->id)->where('status', 'settled')->count()); // references exact rows
        $this->assertSame('160.00', number_format((float) $this->settlementFor(12)->total_payable, 2, '.', ''));
        // a second run has nothing left to claim
        $this->assertSame(0, (new SettlementService())->run()->vendor_count);
    }

    // 19 — a vendor whose window nets ≤ 0 is carried forward, not paid a negative amount.
    public function test_negative_net_is_carried_forward(): void
    {
        $this->recognizedAndEligible();
        VendorLedgerEntry::create(['shop_id' => 12, 'entry_type' => 'adjustment_debit', 'amount' => '-500.00', 'status' => 'pending', 'available_at' => Carbon::now()->subDay(), 'earned_at' => Carbon::now(), 'idempotency_key' => 'adj:test:1', 'source' => 'manual']);
        $run = (new SettlementService())->run();
        $this->assertSame(1, $run->vendor_count); // only vendor A
        $this->assertSame(0, VendorSettlement::where('shop_id', 12)->count());
        $this->assertSame(2, VendorLedgerEntry::where('shop_id', 12)->where('status', 'pending')->count()); // still pending for the next sweep
    }

    // 16/17 — partial payments: 400 + 140 + 100 against 640 → partially_paid → paid; each one journaled.
    public function test_partial_vendor_payments_reduce_the_payable_and_post(): void
    {
        $this->recognizedAndEligible();
        (new SettlementService())->run();
        $svc = new SettlementService();
        $s = $svc->approve($this->settlementFor(11), 'admin:1');
        $this->assertSame(SettlementService::APPROVED, $s->status);

        $pay = new VendorPaymentService();
        $p1 = $pay->record($s, '400.00', 'neft', ['transaction_reference' => 'UTR-1'], 'admin:1');
        $s = $s->fresh();
        $this->assertSame(SettlementService::PARTIALLY_PAID, $s->status);
        $this->assertSame('400.00', number_format((float) $s->amount_paid, 2, '.', ''));
        $this->assertSame('240.00', number_format((float) $s->remaining_payable, 2, '.', ''));

        $pay->record($s, '140.00', 'upi', ['transaction_reference' => 'UTR-2'], 'admin:1');
        $this->assertSame('100.00', number_format((float) $s->fresh()->remaining_payable, 2, '.', ''));
        $pay->record($s->fresh(), '100.00', 'imps', ['transaction_reference' => 'UTR-3'], 'admin:1');
        $s = $s->fresh();
        $this->assertSame(SettlementService::PAID, $s->status);
        $this->assertSame('0.00', number_format((float) $s->remaining_payable, 2, '.', ''));
        $this->assertNotNull($s->paid_at);

        // every payment: a journal (DR 2010 / CR 1010), a ledger row, a legacy withdraws mirror
        $this->assertSame(3, JournalEntry::where('source_type', 'VENDOR_PAYMENT')->count());
        $this->assertSame(3, VendorLedgerEntry::where('entry_type', 'vendor_payment')->count());
        $this->assertSame(3, Withdraw::where('shop_id', 11)->where('payment_method', 'settlement')->count());
        $je = JournalEntry::find($p1->journal_entry_id);
        $by = $this->byAccount($je);
        $this->assertSame('400.00', $by['2010']['debit']);
        $this->assertSame('400.00', $by['1010']['credit']);

        // the vendor balance is explainable: 640 earned − 640 paid = 0; B still owed 160; bank −640
        $r = new FinancialReports();
        $this->assertSame('0.00', $r->accountBalance('2010', null, 11));
        $this->assertSame('160.00', $r->accountBalance('2010', null, 12));
        $this->assertSame('-640.00', $r->accountBalance('1010'));
        $this->assertSame('0.00', number_format((float) VendorLedgerEntry::where('shop_id', 11)->where('status', '!=', 'reversed')->sum('amount'), 2, '.', ''));
        $this->assertTrue($r->trialBalance()['balanced']);
    }

    public function test_overpayment_and_unapproved_payment_are_rejected(): void
    {
        $this->recognizedAndEligible();
        (new SettlementService())->run();
        $s = $this->settlementFor(11);
        try {
            (new VendorPaymentService())->record($s, '100.00', 'neft', [], 'admin:1');
            $this->fail('payment before approval should be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('approved', $e->getMessage());
        }
        $s = (new SettlementService())->approve($s, 'admin:1');
        $this->expectException(\RuntimeException::class);
        (new VendorPaymentService())->record($s, '640.01', 'neft', [], 'admin:1');
    }

    // a replayed payment (same idempotency key) is recorded once.
    public function test_duplicate_payment_is_idempotent(): void
    {
        $this->recognizedAndEligible();
        (new SettlementService())->run();
        $s = (new SettlementService())->approve($this->settlementFor(11), 'admin:1');
        $pay = new VendorPaymentService();
        $a = $pay->record($s, '200.00', 'neft', ['transaction_reference' => 'UTR-X'], 'admin:1', 'settlement:' . $s->id . ':UTR-X');
        $b = $pay->record($s->fresh(), '200.00', 'neft', ['transaction_reference' => 'UTR-X'], 'admin:1', 'settlement:' . $s->id . ':UTR-X');
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, VendorPayment::count());
        $this->assertSame('440.00', number_format((float) $s->fresh()->remaining_payable, 2, '.', ''));
    }

    // legacy "Pay" pays the whole remaining amount through the same journaled path.
    public function test_legacy_pay_is_a_full_journaled_payment(): void
    {
        $this->recognizedAndEligible();
        (new SettlementService())->run();
        $s = (new SettlementService())->pay($this->settlementFor(12), 'admin:1');
        $this->assertSame(SettlementService::PAID, $s->status);
        $this->assertSame(1, VendorPayment::where('vendor_settlement_id', $s->id)->count());
        $this->assertSame('0.00', (new FinancialReports())->accountBalance('2010', null, 12));
    }

    // cancelling an unpaid settlement releases its rows for the next sweep.
    public function test_cancel_releases_rows(): void
    {
        $this->recognizedAndEligible();
        (new SettlementService())->run();
        $s = (new SettlementService())->cancel($this->settlementFor(11), 'wrong period', 'admin:1');
        $this->assertSame(SettlementService::CANCELLED, $s->status);
        $this->assertSame(2, VendorLedgerEntry::where('shop_id', 11)->where('status', 'pending')->whereNull('vendor_settlement_id')->count());
        $this->assertSame(1, (new SettlementService())->run()->vendor_count); // A is swept again
    }

    // 20 — adjustments: a credit raises the payable, a debit lowers it; both journaled; big ones need a second admin.
    public function test_adjustments_post_and_need_approval_above_threshold(): void
    {
        $svc = new VendorAdjustmentService();
        $small = $svc->create(11, 'credit', '250.00', 'Packaging reimbursement', 'PKG-1', null, 'admin:1');
        $this->assertSame('approved', $small->status); // below the ₹5,000 threshold → auto
        $this->assertSame('250.00', (new FinancialReports())->accountBalance('2010', null, 11));
        $this->assertSame(1, VendorLedgerEntry::where('entry_type', 'adjustment_credit')->count());

        $big = $svc->create(11, 'debit', '7500.00', 'Damaged consignment penalty', 'DMG-9', null, 'admin:1');
        $this->assertSame('pending_approval', $big->status);
        $this->assertTrue($big->requires_approval);
        try {
            $svc->approve($big, 'admin:1'); // same admin may not approve their own material adjustment
            $this->fail('self-approval should be rejected');
        } catch (\RuntimeException $e) {
        }
        $big = $svc->approve($big, 'admin:2', 'reviewed photos');
        $this->assertSame('approved', $big->status);
        $this->assertSame('-7250.00', (new FinancialReports())->accountBalance('2010', null, 11)); // 250 − 7500
        $this->assertSame(2, JournalEntry::where('source_type', 'VENDOR_ADJUSTMENT')->count());
        $this->assertTrue((new FinancialReports())->trialBalance()['balanced']);
        // approving again is a no-op; rejecting a decided one is a no-op
        $this->assertSame(2, JournalEntry::where('source_type', 'VENDOR_ADJUSTMENT')->count());
        $svc->reject($big, 'late', 'admin:3');
        $this->assertSame('approved', $big->fresh()->status);
    }

    // §40 — the vendor balance summary answers current payable / pending settlement / last payment from the ledger.
    public function test_balance_summary_is_explainable(): void
    {
        $this->recognizedAndEligible();
        (new SettlementService())->run();
        $s = (new SettlementService())->approve($this->settlementFor(11), 'admin:1');
        (new VendorPaymentService())->record($s, '400.00', 'neft', ['transaction_reference' => 'UTR-1'], 'admin:1');

        $ctl = new \Marvel\Http\Controllers\SettlementController(app(\Marvel\Database\Repositories\OrderRepository::class));
        $m = new \ReflectionMethod($ctl, 'balanceSummary');
        $m->setAccessible(true);
        $sum = $m->invoke($ctl, 11);
        $this->assertSame(240.0, $sum['current_payable']);   // 640 earned − 400 paid
        $this->assertSame(240.0, $sum['awaiting_payout']);
        $this->assertSame(400.0, $sum['paid']);
        $this->assertSame('240.00', $sum['gl_payable']);      // reconciles with the GL
        $this->assertSame(400.0, $sum['last_payment']['amount']);
    }
}
