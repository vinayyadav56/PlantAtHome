<?php

namespace Tests\Feature\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Marvel\Database\Models\Accounting\AccountingPeriod;
use Marvel\Database\Models\Accounting\JournalEntry;
use Marvel\Database\Models\VendorLedgerEntry;
use Marvel\Database\Models\VendorSettlement;
use Marvel\Events\RefundRequested;
use Marvel\Events\RefundUpdate;
use Marvel\Services\Accounting\AccountingPostingService;
use Marvel\Services\Accounting\Exceptions\ClosedPeriodException;
use Marvel\Services\Accounting\FinancialReports;
use Marvel\Services\Accounting\JournalService;
use Marvel\Services\Accounting\OpeningBalanceService;
use Marvel\Services\Accounting\PeriodService;
use Marvel\Services\Accounting\ReconciliationEngine;
use Marvel\Services\Accounting\RefundService;
use Marvel\Services\Accounting\VendorPaymentService;
use Marvel\Services\SettlementService;

class ReconciliationTest extends OrdersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([RefundRequested::class, RefundUpdate::class]);
    }

    /** §68 up to the pot refund paid via gateway: capture → recognise → settle → pay A ₹400 → refund pot. */
    private function s68Books(): \Marvel\Database\Models\Order
    {
        $order = $this->s68Order();
        AccountingPostingService::make()->recordPaymentCaptured($order, 'razorpay', 'pay_S68', 106000, 0, 0, ['status' => 'captured']);
        AccountingPostingService::make()->recognizeOrder($order->fresh(), 'test');
        VendorLedgerEntry::query()->update(['available_at' => Carbon::now()->subDay()]);
        $ss = new SettlementService();
        $ss->run();
        $a = VendorSettlement::where('shop_id', 11)->first();
        $ss->approve($a, 'admin:1');
        (new VendorPaymentService())->record($a->fresh(), '400.00', 'neft', ['transaction_reference' => 'UTR-A1'], 'admin:1');
        $pot = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 102)->value('id');
        $refund = app(\Marvel\Database\Repositories\RefundRepository::class)->createSliced($order->fresh(), ['order_id' => $order->id, 'customer_id' => 5, 'title' => 'pot'], 'items', [['order_item_id' => $pot, 'quantity' => 1]], null, 'gateway');
        $refund->forceFill(['status' => 'approved'])->saveQuietly();
        $svc = RefundService::make()->withGatewayRefunder(fn ($pid, $paise, $receipt) => ['id' => 'rfnd_1', 'amount' => $paise, 'status' => 'processed']);
        $svc->post($refund->fresh(), 'admin:1');
        $svc->payout($refund->fresh(), 'gateway', 'admin:1');
        return $order->fresh();
    }

    // 48 — clean books: every check is clean, the gate passes, and the §68 end state is exactly as specified.
    public function test_clean_books_pass_every_check_and_the_gate(): void
    {
        $this->s68Books();
        $r = (new ReconciliationEngine())->run(null, null, null, 'admin:1');
        $this->assertSame('clean', $r['run']->status, json_encode($r['all_findings']));
        $this->assertSame([], $r['all_findings']);
        $this->assertSame(2, $r['summary']['vendor']['shops_checked']);
        $this->assertSame(1, $r['summary']['payment']['orders_checked']);
        $this->assertSame('45.48', $r['summary']['gst']['snapshot']['cgst']);
        $this->assertSame('38.14', $r['summary']['gst']['credit_notes']['cgst']);
        $g = (new ReconciliationEngine())->gate();
        $this->assertTrue($g['pass'], json_encode($g['checks']));
        $rep = new FinancialReports();
        $this->assertSame('-160.00', $rep->accountBalance('2010', null, 11)); // 640 − 400 paid − 400 clawback
        $this->assertSame('160.00', $rep->accountBalance('2010', null, 12));
        $this->assertSame('7.34', $rep->accountBalance('2020'));
        $this->assertSame('7.33', $rep->accountBalance('2030'));
        $this->assertSame('490.48', $rep->accountBalance('4010'));
        $this->assertSame('560.00', $rep->accountBalance('1020')); // 1060 captured − 500 refunded
    }

    // 49 — a sub-ledger row the GL never saw is found with the exact difference; explaining it needs a note.
    public function test_vendor_ledger_drift_is_detected_with_the_exact_difference(): void
    {
        $this->s68Books();
        $anyJe = JournalEntry::first()->id;
        VendorLedgerEntry::create(['shop_id' => 11, 'order_id' => null, 'entry_type' => 'adjustment_credit', 'amount' => '10.00', 'status' => 'pending', 'journal_entry_id' => $anyJe, 'idempotency_key' => 'rogue:1', 'source' => 'test', 'earned_at' => Carbon::now()]);
        $engine = new ReconciliationEngine();
        $r = $engine->run(null, null, ['vendor'], 'admin:1');
        $this->assertSame('findings', $r['run']->status);
        $this->assertCount(1, $r['all_findings']);
        $f = $r['all_findings'][0];
        $this->assertSame('shop', $f['subject_type']);
        $this->assertSame(11, (int) $f['subject_id']);
        $this->assertSame('-160.00', $f['expected']);
        $this->assertSame('-150.00', $f['actual']);
        $this->assertSame('10.00', $f['difference']);
        $this->assertFalse($engine->gate()['pass']);
        // a re-run does not duplicate the still-open finding
        $again = $engine->run(null, null, ['vendor'], 'admin:1');
        $this->assertCount(0, $again['findings']);
        $this->assertSame(1, DB::table('acc_reconciliation_findings')->count());
        $resolved = $engine->resolve($f['id'], 'explained', 'Manual test row; removed.', 'admin:2');
        $this->assertSame('explained', $resolved->status);
        $this->assertSame('admin:2', $resolved->resolved_by);
        $this->assertSame(1, DB::table('acc_audit_log')->where('auditable_type', 'reconciliation_finding')->where('action', 'explained')->count());
    }

    // 44 — payment reconciliation: a capture that does not match what the order says was paid.
    public function test_payment_capture_mismatch_is_a_finding(): void
    {
        $order = $this->s68Order(['order_status' => 'order-processing']);
        AccountingPostingService::make()->recordPaymentCaptured($order, 'razorpay', 'pay_short', 100000, 0, 0, ['status' => 'captured']);
        $r = (new ReconciliationEngine())->run(null, null, ['payment']);
        $this->assertCount(1, $r['all_findings']);
        $this->assertSame('1060.00', $r['all_findings'][0]['expected']);
        $this->assertSame('1000.00', $r['all_findings'][0]['actual']);
        $this->assertSame('-60.00', $r['all_findings'][0]['difference']);
    }

    // GST: the tax ledger equals the snapshot net of credit notes; a stray tax posting is found.
    public function test_gst_ledger_matches_snapshot_net_of_credit_notes(): void
    {
        $this->s68Books();
        $engine = new ReconciliationEngine();
        $this->assertSame([], $engine->run(null, null, ['gst'])['all_findings']);
        (new JournalService())->postLines([
            ['account' => '6060', 'debit' => '1.00'], ['account' => '2020', 'credit' => '1.00'],
        ], ['source_type' => 'MANUAL', 'source_key' => 'MANUAL:stray', 'description' => 'stray']);
        $f = $engine->run(null, null, ['gst'])['all_findings'];
        $this->assertCount(1, $f);
        $this->assertSame('cgst', $f[0]['subject_id']);
        $this->assertSame('7.34', $f[0]['expected']);
        $this->assertSame('8.34', $f[0]['actual']);
    }

    // 50 — periods: close needs a clean reconciliation; a closed month refuses manual posts and redirects late system events.
    public function test_period_close_requires_clean_reconciliation_and_blocks_posting(): void
    {
        $this->s68Books();
        $ps = new PeriodService();
        // put everything into last month so the period has ended
        $lastMonth = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        DB::table('acc_journal_entries')->update(['entry_date' => $lastMonth->toDateString()]);
        DB::table('acc_journal_lines')->update(['entry_date' => $lastMonth->toDateString()]);
        $period = AccountingPeriod::forDate($lastMonth);
        DB::table('acc_journal_entries')->update(['period_id' => $period->id]);

        $rogue = VendorLedgerEntry::create(['shop_id' => 12, 'order_id' => null, 'entry_type' => 'adjustment_credit', 'amount' => '5.00', 'status' => 'pending', 'journal_entry_id' => JournalEntry::first()->id, 'idempotency_key' => 'rogue:2', 'source' => 'test', 'earned_at' => Carbon::now()]);
        try {
            $ps->close($period->id, 'admin:1');
            $this->fail('close should be refused with an open finding');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('open reconciliation finding', $e->getMessage());
        }
        $this->assertSame('open', $period->fresh()->status);
        $this->assertSame(1, DB::table('acc_reconciliation_findings')->where('status', 'open')->count()); // the refused close keeps its findings
        $this->assertSame(1, DB::table('acc_reconciliation_runs')->count());
        $rogue->delete();
        foreach (DB::table('acc_reconciliation_findings')->where('status', 'open')->get() as $f) {
            (new ReconciliationEngine())->resolve($f->id, 'resolved', 'test row removed', 'admin:1');
        }
        $closed = $ps->close($period->id, 'admin:1', 'Month-end close');
        $this->assertSame('closed', $closed->status);
        $this->assertNotNull($closed->closed_at);

        // a MANUAL entry into the closed month is refused
        try {
            (new JournalService())->postLines([['account' => '6060', 'debit' => '1.00'], ['account' => '1010', 'credit' => '1.00']],
                ['source_type' => 'MANUAL', 'source_key' => 'MANUAL:late', 'entry_date' => $lastMonth->copy()->addDays(3)->toDateString(), 'metadata' => ['redirect_closed_period' => false]]);
            $this->fail('posting into a closed period must be refused');
        } catch (ClosedPeriodException $e) {
            $this->assertStringContainsString('closed', $e->getMessage());
        }
        // a late SYSTEM event is redirected into the open month and flagged, original date kept
        AccountingPeriod::forDate(Carbon::today());
        $je = (new JournalService())->postLines([['account' => '6060', 'debit' => '2.00'], ['account' => '1010', 'credit' => '2.00']],
            ['source_type' => 'SHIPMENT_COST', 'source_key' => 'SYSTEM:late', 'entry_date' => $lastMonth->copy()->addDays(3)->toDateString()]);
        $this->assertSame('posted', $je->status);
        $this->assertTrue($je->requires_reconciliation);
        $this->assertSame($lastMonth->copy()->addDays(3)->toDateString(), $je->metadata['original_date']);
        $this->assertSame(Carbon::today()->format('Y-m'), $je->entry_date->format('Y-m'));
        $this->assertSame(AccountingPeriod::forDate(Carbon::today())->id, $je->period_id);
        $this->assertSame(1, DB::table('acc_journal_lines')->where('journal_entry_id', $je->id)->where('entry_date', $je->entry_date->toDateString())->count() > 0 ? 1 : 0);
        // ...and it surfaces as a journal finding until explained
        $f = (new ReconciliationEngine())->run(null, null, ['journal'])['all_findings'];
        $this->assertNotEmpty(array_filter($f, fn ($x) => $x['subject_type'] === 'journal_entry' && (int) $x['subject_id'] === $je->id));
        $reopened = $ps->reopen($period->id, 'late invoice', 'admin:1');
        $this->assertSame('open', $reopened->status);
    }

    // D4 — an opening balance is posted once, flagged until confirmed, and mirrored into the sub-ledger.
    public function test_opening_balance_posts_flagged_and_confirms(): void
    {
        $svc = new OpeningBalanceService();
        $je = $svc->post(11, '1500.00', '2026-09-01', 'admin:1', 'from legacy balances');
        $by = $this->byAccount($je);
        $this->assertSame('1500.00', $by['3030']['debit']);
        $this->assertSame('1500.00', $by['2010']['credit']);
        $this->assertTrue($je->requires_reconciliation);
        $this->assertSame($je->id, $svc->post(11, '999.00', null, 'admin:1')->id); // idempotent per shop
        $row = VendorLedgerEntry::where('entry_type', 'opening_balance')->where('shop_id', 11)->first();
        $this->assertSame('1500.00', number_format((float) $row->amount, 2, '.', ''));
        $this->assertSame($je->id, $row->journal_entry_id);
        $neg = $svc->post(12, '-250.00', '2026-09-01', 'admin:1');
        $nb = $this->byAccount($neg);
        $this->assertSame('250.00', $nb['2010']['debit']);
        $this->assertSame('250.00', $nb['3030']['credit']);
        $engine = new ReconciliationEngine();
        $this->assertSame([], $engine->run(null, null, ['vendor'])['all_findings']); // sub-ledger == GL incl. opening rows
        $this->assertFalse($engine->gate()['pass']);
        $this->assertSame(2, $engine->gate()['checks']['unconfirmed_opening_balances']);
        // legacy check: balances says 1500 for A (match) and 300 for B (mismatch vs −250)
        DB::table('balances')->insert([['shop_id' => 11, 'current_balance' => 1500], ['shop_id' => 12, 'current_balance' => 300]]);
        $legacy = $engine->run(null, null, ['legacy'])['all_findings'];
        $this->assertCount(1, $legacy);
        $this->assertSame(12, (int) $legacy[0]['subject_id']);
        $this->assertSame('-550.00', $legacy[0]['difference']);
        $svc->confirm(11, 'admin:2', 'matches legacy');
        $svc->confirm(12, 'admin:2', 'vendor owed us, agreed');
        $this->assertFalse($je->fresh()->requires_reconciliation);
        $this->assertSame(0, $engine->gate()['checks']['unconfirmed_opening_balances']);
        $prefill = $svc->prefill();
        $this->assertTrue(collect($prefill)->firstWhere('shop_id', 11)['confirmed']);
    }

    // orders flagged by the posting service are findings; completed post-cutover orders without a journal too.
    public function test_flagged_and_unposted_orders_are_findings(): void
    {
        $o1 = $this->s68Order(['tracking_number' => 'FLAG-1', 'financial_status' => 'requires_reconciliation']);
        $o2 = $this->s68Order(['tracking_number' => 'UNPOSTED-1']);
        $engine = new ReconciliationEngine(new \Marvel\Services\Accounting\AccountingConfig(['accounting' => ['enabled' => true, 'cutover_date' => '2026-01-01']]));
        $f = $engine->run(null, null, ['journal'])['all_findings'];
        $ids = array_map(fn ($x) => (int) $x['subject_id'], array_filter($f, fn ($x) => $x['subject_type'] === 'order'));
        // without a cutover date (pre-cutover history is never posted) the sweep stays silent
        $this->assertSame([], array_filter((new ReconciliationEngine())->run(null, null, ['journal'])['all_findings'], fn ($x) => $x['subject_type'] === 'order' && (int) $x['subject_id'] === $o2->id));
        sort($ids);
        $this->assertSame([$o1->id, $o2->id], $ids);
    }

    // review: an explained finding stays quiet on later runs; a resolved one that recurs is raised again.
    public function test_explained_findings_are_not_re_raised(): void
    {
        $this->s68Books();
        VendorLedgerEntry::create(['shop_id' => 11, 'order_id' => null, 'entry_type' => 'adjustment_credit', 'amount' => '10.00', 'status' => 'pending', 'journal_entry_id' => JournalEntry::first()->id, 'idempotency_key' => 'rogue:3', 'source' => 'test', 'earned_at' => Carbon::now()]);
        $engine = new ReconciliationEngine();
        $f = $engine->run(null, null, ['vendor'])['findings'];
        $engine->resolve($f[0]['id'], 'explained', 'accepted legacy drift', 'admin:1');
        $again = $engine->run(null, null, ['vendor']);
        $this->assertCount(0, $again['findings']);
        $this->assertSame('clean', $again['run']->status); // an explained difference no longer blocks
        $this->assertSame(1, $again['summary']['explained_differences']);
        $this->assertTrue($engine->gate()['pass']);
        $this->assertSame(1, DB::table('acc_reconciliation_findings')->count());
        $engine->resolve($f[0]['id'], 'resolved', 'thought it was fixed', 'admin:1');
        $this->assertCount(1, $engine->run(null, null, ['vendor'])['findings']); // recurred → a new open finding
    }

    // review: the running month cannot be closed; a late event still posts when the current period row does not exist yet.
    public function test_running_month_cannot_close_and_late_events_create_the_current_period(): void
    {
        $ps = new PeriodService();
        $current = AccountingPeriod::forDate(Carbon::today());
        try {
            $ps->close($current->id, 'admin:1');
            $this->fail('the running month must not close');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not ended', $e->getMessage());
        }
        $lastMonth = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        $closed = AccountingPeriod::forDate($lastMonth);
        $closed->update(['status' => 'closed', 'closed_at' => now()]);
        AccountingPeriod::where('id', $current->id)->delete();
        $je = (new JournalService())->postLines([['account' => '6060', 'debit' => '2.00'], ['account' => '1010', 'credit' => '2.00']],
            ['source_type' => 'SHIPMENT_COST', 'source_key' => 'SYSTEM:late2', 'entry_date' => $lastMonth->copy()->addDays(2)->toDateString()]);
        $this->assertSame('posted', $je->status);
        $this->assertSame(Carbon::today()->format('Y-m'), $je->entry_date->format('Y-m'));
        $this->assertTrue($je->requires_reconciliation);
    }
}
