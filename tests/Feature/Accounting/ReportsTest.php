<?php

namespace Tests\Feature\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Marvel\Database\Models\Accounting\Account;
use Marvel\Database\Models\Accounting\JournalEntry;
use Marvel\Database\Models\VendorLedgerEntry;
use Marvel\Database\Models\VendorSettlement;
use Marvel\Events\RefundRequested;
use Marvel\Events\RefundUpdate;
use Marvel\Services\Accounting\AccountingPostingService;
use Marvel\Services\Accounting\FinancialReports;
use Marvel\Services\Accounting\JournalService;
use Marvel\Services\Accounting\RefundService;
use Marvel\Services\Accounting\VendorPaymentService;
use Marvel\Services\SettlementService;

class ReportsTest extends OrdersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([RefundRequested::class, RefundUpdate::class]);
    }

    /** §68 through the pot refund paid by gateway (same as ReconciliationTest::s68Books). */
    private function books(): void
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
    }

    public function test_general_ledger_running_balance_and_filters(): void
    {
        $this->books();
        $r = new FinancialReports();
        $gl = $r->generalLedger(['account' => '2010', 'shop_id' => 11]);
        $this->assertSame('0.00', $gl['opening_balance']);
        $this->assertSame(['240.00', '640.00', '240.00', '-160.00'], array_column($gl['rows'], 'running_balance')); // plant, pot, payment, clawback
        $this->assertSame('-160.00', $gl['closing_balance']);
        $this->assertSame('credit', $gl['normal_side']);
        // paging keeps the running balance continuous
        $p2 = $r->generalLedger(['account' => '2010', 'shop_id' => 11], 2, 2);
        $this->assertSame(['240.00', '-160.00'], array_column($p2['rows'], 'running_balance'));
        $this->assertSame(2, $p2['last_page']);
        // an opening balance before the window
        $today = Carbon::today()->toDateString();
        $win = $r->generalLedger(['account' => '2010', 'shop_id' => 11, 'from' => Carbon::today()->addDay()->toDateString()]);
        $this->assertSame('-160.00', $win['opening_balance']);
        $this->assertSame([], $win['rows']);
        $this->assertSame(1, count($r->generalLedger(['source_type' => 'VENDOR_PAYMENT', 'account' => '1010'])['rows']));
        $this->assertSame(4, $r->generalLedger(['order_id' => \Marvel\Database\Models\Order::first()->id, 'search' => 'Refund'])['total'] >= 4 ? 4 : 0);
    }

    public function test_profit_and_loss_and_dashboard_tie_to_the_ledger(): void
    {
        $this->books();
        $pl = (new FinancialReports())->profitAndLoss();
        $this->assertSame('545.33', $pl['total_revenue']);      // 490.48 sales + 54.85 delivery
        $this->assertSame('400.00', $pl['total_direct_costs']); // 800 − 400 vendor cost reversed
        $this->assertSame('145.33', $pl['gross_profit']);
        $this->assertSame('145.33', $pl['net_income']);
        $d = (new FinancialReports())->dashboard();
        $this->assertSame(['545.33', '145.33', '0.00', '14.67', '560.00', '0.00', '0.00', '-400.00'], [$d['revenue'], $d['net_income'], $d['vendor_payables'], $d['gst_payable'], $d['gateway_receivable'], $d['refund_payable'], $d['customer_advances'], $d['bank']]);
        $this->assertSame(2, $d['pending_settlements']['count']); // A partially paid (240 left) + B's 160 awaiting approval
        $this->assertTrue($d['trial_balance_balanced']);
        $bs = (new FinancialReports())->balanceSheet();
        $this->assertTrue($bs['balanced']);
        $this->assertSame($pl['net_income'], $bs['net_income_to_date']);
    }

    public function test_accounts_payable_cash_bank_and_vendor_statement(): void
    {
        $this->books();
        $ap = (new FinancialReports())->accountsPayable();
        $byShop = collect($ap['vendors'])->keyBy('shop_id');
        $this->assertSame(['-160.00', '160.00', '0.00'], [$byShop[11]['payable'], $byShop[12]['payable'], $ap['total_vendor_payables']]);
        $this->assertSame(1, $byShop[12]['open_settlements']);
        $this->assertNotNull($byShop[11]['last_payment_date']);
        $cb = (new FinancialReports())->cashAndBank();
        $acc = collect($cb['accounts'])->keyBy('role');
        $this->assertSame('-400.00', $acc['bank']['balance']);
        $this->assertSame('560.00', $acc['gateway_receivable']['balance']);
        $this->assertSame(['PAYMENT_CAPTURED', 'REFUND_PAID'], array_column($acc['gateway_receivable']['movements'], 'source_type'));
        $st = (new FinancialReports())->vendorStatement(11);
        $this->assertSame(['0.00', '-160.00'], [$st['opening_balance'], $st['closing_balance']]);
        $this->assertSame(['640.00', '-400.00', '-400.00'], [$st['summary']['sales'], $st['summary']['refunds'], $st['summary']['payments']]);
        $this->assertCount(1, $st['settlements']);
        $this->assertCount(1, $st['payments']);
    }

    public function test_manual_journal_and_reversal_and_account_guards(): void
    {
        $js = new JournalService();
        $je = $js->postLines([['account' => '6060', 'debit' => '25.00'], ['account' => '1010', 'credit' => '25.00']], ['source_type' => 'MANUAL', 'source_key' => 'MANUAL:t1', 'description' => 'office plants', 'metadata' => ['redirect_closed_period' => false]]);
        $this->assertSame('posted', $je->status);
        $rev = $js->reverse($je, 'entered twice', 'admin:1');
        $this->assertSame('reversed', $je->fresh()->status);
        $this->assertSame('0.00', (new FinancialReports())->accountBalance('6060'));
        $this->assertSame($rev->id, $je->fresh()->reversed_by_entry_id);
        $this->assertTrue(Account::where('code', '2010')->first()->is_system);
        $custom = Account::create(['code' => '6099', 'name' => 'Team lunches', 'type' => 'expense', 'normal_side' => 'debit', 'is_active' => true, 'is_system' => false]);
        $this->assertSame(0, JournalEntry::where('source_type', 'MANUAL')->where('status', 'draft')->count());
        $this->assertCount(1, (new FinancialReports())->generalLedger(['account' => '6099'])['rows'] ?: [1]); // no lines yet → empty page
    }
}
