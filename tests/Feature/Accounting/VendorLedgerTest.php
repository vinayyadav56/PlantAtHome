<?php

namespace Tests\Feature\Accounting;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Accounting\JournalEntry;
use Marvel\Database\Models\VendorLedgerEntry;
use Marvel\Services\Accounting\AccountingPostingService;
use Marvel\Services\Accounting\FinancialReports;
use Marvel\Services\VendorLedgerService;

class VendorLedgerTest extends OrdersTestCase
{
    // 15 — a multi-vendor order writes one sub-ledger row per assigned LINE, per vendor, linked to the journal.
    public function test_recognition_writes_per_line_vendor_ledger_rows(): void
    {
        $order = $this->s68Order();
        $je = AccountingPostingService::make()->recognizeOrder($order, 'test');

        $rows = VendorLedgerEntry::where('order_id', $order->id)->orderBy('order_item_id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame([11, 11, 12], $rows->pluck('shop_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['240.00', '400.00', '160.00'], $rows->map(fn ($r) => number_format((float) $r->amount, 2, '.', ''))->all());
        $this->assertSame(['sale', 'sale', 'sale'], $rows->pluck('entry_type')->all());
        $this->assertSame([$je->id, $je->id, $je->id], $rows->pluck('journal_entry_id')->map(fn ($v) => (int) $v)->all());
        $this->assertTrue($rows->every(fn ($r) => $r->status === 'pending' && $r->available_at !== null && $r->order_item_id !== null));
        $this->assertSame('cost_sheet', $rows->first()->commission_rule_snapshot['mode']);
        // positive = owed TO the vendor (one sign convention)
        $this->assertSame('640.00', number_format((float) VendorLedgerEntry::where('shop_id', 11)->sum('amount'), 2, '.', ''));
    }

    // 48 — the vendor sub-ledger reconciles to the GL payable per shop.
    public function test_vendor_ledger_reconciles_with_gl_payables(): void
    {
        $order = $this->s68Order();
        AccountingPostingService::make()->recognizeOrder($order, 'test');
        $r = new FinancialReports();
        foreach ([11 => '640.00', 12 => '160.00'] as $shop => $expected) {
            $ledger = number_format((float) VendorLedgerEntry::where('shop_id', $shop)->where('status', '!=', 'reversed')->sum('amount'), 2, '.', '');
            $this->assertSame($expected, $ledger);
            $this->assertSame($expected, $r->accountBalance('2010', null, $shop));
        }
    }

    // duplicate recognition never duplicates ledger rows (idempotency_key is the arbiter).
    public function test_ledger_rows_are_idempotent(): void
    {
        $order = $this->s68Order();
        $svc = AccountingPostingService::make();
        $svc->recognizeOrder($order, 'test');
        $svc->recognizeOrder($order->fresh(), 'test');
        (new VendorLedgerService())->recordRecognition($order->fresh(), $order->items()->get(), [], JournalEntry::first()); // direct replay with no lines → no-op
        $this->assertSame(3, VendorLedgerEntry::count());
        $this->assertSame(3, VendorLedgerEntry::whereNotNull('idempotency_key')->distinct('idempotency_key')->count('idempotency_key'));
    }

    // de-recognition reverses per line: pending sales are cancelled (both rows reversed, never settle).
    public function test_derecognition_reverses_pending_lines(): void
    {
        $order = $this->s68Order();
        AccountingPostingService::make()->recognizeOrder($order, 'test');
        AccountingPostingService::onOrderStatusChanged($order->fresh(), 'order-completed', 'order-cancelled');

        $this->assertSame(3, VendorLedgerEntry::where('entry_type', 'refund_reversal')->count());
        $this->assertSame(6, VendorLedgerEntry::where('status', 'reversed')->count()); // 3 sales + 3 reversals
        $this->assertSame(0, VendorLedgerEntry::where('status', 'pending')->count());
        $this->assertSame('0.00', number_format((float) VendorLedgerEntry::where('shop_id', 11)->where('status', '!=', 'reversed')->sum('amount'), 2, '.', ''));
        $this->assertSame('0.00', (new FinancialReports())->accountBalance('2010', null, 11));
        // a second signal is a no-op
        AccountingPostingService::onOrderStatusChanged($order->fresh(), 'order-completed', 'order-cancelled');
        $this->assertSame(3, VendorLedgerEntry::where('entry_type', 'refund_reversal')->count());
    }

    // a sale already SETTLED becomes a settle-eligible clawback (nets against future earnings).
    public function test_derecognition_of_settled_line_is_a_clawback(): void
    {
        $order = $this->s68Order();
        AccountingPostingService::make()->recognizeOrder($order, 'test');
        VendorLedgerEntry::where('shop_id', 12)->update(['status' => 'settled', 'vendor_settlement_id' => 999]);
        AccountingPostingService::onOrderStatusChanged($order->fresh(), 'order-completed', 'order-refunded');

        $claw = VendorLedgerEntry::where('shop_id', 12)->where('entry_type', 'refund_reversal')->first();
        $this->assertSame('pending', $claw->status);              // settle-eligible
        $this->assertNotNull($claw->available_at);
        $this->assertSame('-160.00', number_format((float) $claw->amount, 2, '.', ''));
        $this->assertSame('settled', VendorLedgerEntry::where('shop_id', 12)->where('entry_type', 'sale')->first()->status); // history intact
    }

    // the switch is unified: accounting ON ⇒ the vendor ledger/settlement is the system of record; '' env is ignored.
    public function test_switch_is_unified_and_empty_env_is_unset(): void
    {
        putenv('MARKETPLACE_LEDGER=');
        $_ENV['MARKETPLACE_LEDGER'] = '';
        $_SERVER['MARKETPLACE_LEDGER'] = '';
        $this->assertTrue((new VendorLedgerService())->enabled());
        $this->assertTrue(VendorLedgerService::settlementActive());
        DB::table('settings')->update(['options' => json_encode(['accounting' => ['enabled' => false]])]);
        $this->assertFalse((new VendorLedgerService())->enabled());
        putenv('MARKETPLACE_LEDGER'); unset($_ENV['MARKETPLACE_LEDGER'], $_SERVER['MARKETPLACE_LEDGER']);
    }

    // the legacy order-level adapters are inert while accounting owns the ledger (no double rows).
    public function test_legacy_record_sale_is_noop_when_accounting_on(): void
    {
        $order = $this->s68Order();
        AccountingPostingService::make()->recognizeOrder($order, 'test');
        (new VendorLedgerService())->recordSale($order->fresh());
        (new VendorLedgerService())->reverseSale($order->fresh());
        $this->assertSame(3, VendorLedgerEntry::count());
        $this->assertSame(0, VendorLedgerEntry::where('entry_type', 'refund_reversal')->count());
    }
}
