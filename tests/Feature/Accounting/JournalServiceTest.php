<?php

namespace Tests\Feature\Accounting;

use Marvel\Database\Models\Accounting\AccountingPeriod;
use Marvel\Database\Models\Accounting\JournalEntry;
use Marvel\Database\Models\Accounting\JournalLine;
use Marvel\Services\Accounting\Exceptions\ClosedPeriodException;
use Marvel\Services\Accounting\Exceptions\ImmutableJournalException;
use Marvel\Services\Accounting\Exceptions\UnbalancedJournalException;
use Marvel\Services\Accounting\FinancialReports;
use Marvel\Services\Accounting\JournalService;
use Marvel\Services\Accounting\MoneyBridge;

class JournalServiceTest extends AccountingTestCase
{
    private function svc(): JournalService
    {
        return new JournalService();
    }

    private function capture(string $key = 'PAYMENT_CAPTURED:rzp:pay_1', string $amount = '1060.00'): JournalEntry
    {
        return $this->svc()->postLines([
            ['account' => '1020', 'debit' => $amount, 'order_id' => 1],
            ['account' => '2070', 'credit' => $amount, 'order_id' => 1],
        ], ['source_type' => 'PAYMENT_CAPTURED', 'source_id' => 'pay_1', 'source_key' => $key, 'description' => 'capture']);
    }

    // 1 — a balanced entry posts, is numbered, and stores paise-exact totals.
    public function test_balanced_journal_posts(): void
    {
        $e = $this->capture();
        $this->assertSame(JournalEntry::POSTED, $e->status);
        $this->assertSame('JE-' . date('Y') . '-000001', $e->entry_number);
        $this->assertSame('1060.00', (string) $e->total_debit);
        $this->assertSame('1060.00', (string) $e->total_credit);
        $this->assertCount(2, $e->lines);
        $this->assertNotNull($e->period_id);
        $this->assertNotNull($e->posted_at);
    }

    // 2 — an unbalanced entry is rejected and nothing is persisted.
    public function test_unbalanced_journal_rejected(): void
    {
        $this->expectException(UnbalancedJournalException::class);
        try {
            $this->svc()->postLines([
                ['account' => '1020', 'debit' => '100.00'],
                ['account' => '2070', 'credit' => '90.00'],
            ], ['source_type' => 'TEST', 'source_key' => 'TEST:unbalanced']);
        } finally {
            $this->assertSame(0, JournalEntry::count());
            $this->assertSame(0, JournalLine::count());
        }
    }

    // 2b — a line carrying both sides, or negative, is rejected.
    public function test_line_must_have_exactly_one_side(): void
    {
        $this->expectException(UnbalancedJournalException::class);
        $this->svc()->postLines([
            ['account' => '1020', 'debit' => '50.00', 'credit' => '50.00'],
            ['account' => '2070', 'credit' => '50.00'],
        ], ['source_type' => 'TEST', 'source_key' => 'TEST:both-sides']);
    }

    // 3 — posted entries and their lines cannot be edited or deleted.
    public function test_posted_journal_is_immutable(): void
    {
        $e = $this->capture();
        $line = $e->lines->first();

        try {
            $line->update(['debit' => '999.00']);
            $this->fail('line update should have thrown');
        } catch (ImmutableJournalException $ex) {
            $this->assertSame('1060.00', (string) $line->fresh()->debit);
        }
        try {
            $e->update(['total_debit' => '1.00']);
            $this->fail('entry amount update should have thrown');
        } catch (ImmutableJournalException $ex) {
            $this->assertSame('1060.00', (string) $e->fresh()->total_debit);
        }
        try {
            $e->fresh()->delete();
            $this->fail('delete should have thrown');
        } catch (ImmutableJournalException $ex) {
            $this->assertSame(1, JournalEntry::count());
        }
        try {
            $e->fresh()->update(['status' => JournalEntry::DRAFT]);
            $this->fail('un-posting should have thrown');
        } catch (ImmutableJournalException $ex) {
            $this->assertSame(JournalEntry::POSTED, $e->fresh()->status);
        }
    }

    // 4 — a reversal mirrors every line with the same dimensions and nets the account to zero.
    public function test_reversal_mirrors_and_marks_original(): void
    {
        $e = $this->capture();
        $rev = $this->svc()->reverse($e, 'duplicate capture');

        $this->assertSame(JournalEntry::POSTED, $rev->status);
        $this->assertSame('REVERSAL:' . $e->id, $rev->source_key);
        $this->assertSame($e->id, $rev->reverses_entry_id);
        $this->assertSame(JournalEntry::REVERSED, $e->fresh()->status);
        $this->assertSame($rev->id, $e->fresh()->reversed_by_entry_id);

        $orig = $e->lines->keyBy('account_id');
        foreach ($rev->lines as $l) {
            $this->assertSame((string) $orig[$l->account_id]->credit, (string) $l->debit);
            $this->assertSame((string) $orig[$l->account_id]->debit, (string) $l->credit);
            $this->assertSame(1, $l->order_id); // dimension carried
        }
        $this->assertSame('0.00', (new FinancialReports())->accountBalance('1020'));
        $this->assertSame('0.00', (new FinancialReports())->accountBalance('2070'));

        // reversing twice is refused (original is no longer POSTED)
        $this->expectException(ImmutableJournalException::class);
        $this->svc()->reverse($e->fresh(), 'again');
    }

    // 5 — the trial balance balances across several entries incl. a reversal.
    public function test_trial_balance_balances(): void
    {
        $this->capture();
        $rec = $this->svc()->postLines([
            ['account' => '2070', 'debit' => '1060.00'],
            ['account' => '4010', 'credit' => '914.21'],
            ['account' => '2020', 'credit' => '45.48', 'tax_kind' => 'cgst'],
            ['account' => '2030', 'credit' => '45.46', 'tax_kind' => 'sgst'],
            ['account' => '4030', 'credit' => '54.85'],
        ], ['source_type' => 'ORDER_RECOGNIZED', 'source_id' => 1, 'source_key' => 'ORDER_RECOGNIZED:1']);
        $this->svc()->reverse($rec, 'cancelled after delivery');

        $tb = (new FinancialReports())->trialBalance();
        $this->assertTrue($tb['balanced'], json_encode($tb));
        $this->assertSame($tb['total_debit'], $tb['total_credit']);
        $this->assertSame('3180.00', $tb['total_debit']); // 1060 + 1060 + 1060
    }

    // 6 — balance sheet identity: assets = liabilities + equity (incl. net income).
    public function test_balance_sheet_identity(): void
    {
        $this->capture();
        $this->svc()->postLines([
            ['account' => '2070', 'debit' => '1060.00'],
            ['account' => '4010', 'credit' => '914.21'],
            ['account' => '2020', 'credit' => '45.48'],
            ['account' => '2030', 'credit' => '45.46'],
            ['account' => '4030', 'credit' => '54.85'],
        ], ['source_type' => 'ORDER_RECOGNIZED', 'source_id' => 1, 'source_key' => 'ORDER_RECOGNIZED:1']);
        $this->svc()->postLines([
            ['account' => '5020', 'debit' => '800.00'],
            ['account' => '2010', 'credit' => '640.00', 'shop_id' => 11],
            ['account' => '2010', 'credit' => '160.00', 'shop_id' => 12],
        ], ['source_type' => 'ORDER_RECOGNIZED', 'source_id' => 1, 'source_key' => 'ORDER_RECOGNIZED:1:vendor']);

        $bs = (new FinancialReports())->balanceSheet();
        $this->assertTrue($bs['balanced'], json_encode($bs));
        $this->assertSame('1060.00', $bs['total_assets']);
        $this->assertSame('890.94', $bs['total_liabilities']);  // 800 + 45.48 + 45.46
        $this->assertSame('169.06', $bs['net_income_to_date']); // 969.06 revenue − 800 cost
        $this->assertSame('0.00', $bs['difference']);
        // vendor sub-ledger dimension: per-shop payable from the same lines
        $r = new FinancialReports();
        $this->assertSame('640.00', $r->accountBalance('2010', null, 11));
        $this->assertSame('160.00', $r->accountBalance('2010', null, 12));
    }

    // 8 — the same financial event posted twice yields ONE entry (idempotent source_key).
    public function test_duplicate_source_key_returns_existing_entry(): void
    {
        $a = $this->capture();
        $b = $this->capture(); // same key
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame(2, JournalLine::count());
    }

    // §46 — a closed period refuses posting.
    public function test_closed_period_rejects_posting(): void
    {
        $p = AccountingPeriod::forDate(\Carbon\Carbon::parse('2026-08-15'));
        $p->update(['status' => 'closed', 'closed_at' => now()]);

        $this->expectException(ClosedPeriodException::class);
        $this->svc()->postLines([
            ['account' => '1020', 'debit' => '10.00'],
            ['account' => '2070', 'credit' => '10.00'],
        ], ['source_type' => 'TEST', 'source_key' => 'TEST:closed', 'entry_date' => '2026-08-15']);
    }

    // numbering is sequential per year and survives across entries.
    public function test_entry_numbers_are_sequential(): void
    {
        $a = $this->capture('K:1');
        $b = $this->capture('K:2');
        $c = $this->capture('K:3');
        $y = date('Y');
        $this->assertSame(["JE-$y-000001", "JE-$y-000002", "JE-$y-000003"], [$a->entry_number, $b->entry_number, $c->entry_number]);
    }

    // MoneyBridge: decimal strings never pass through floats; allocation sums exactly.
    public function test_money_bridge_precision_and_allocation(): void
    {
        $this->assertSame(42373, MoneyBridge::toMoney('423.73')->amountMinor());
        $this->assertSame(-10, MoneyBridge::toMoney('-0.10')->amountMinor());
        $this->assertSame('1060.00', MoneyBridge::sum(['300', '500.00', 260])->toDecimal());
        $parts = MoneyBridge::allocate(MoneyBridge::toMoney('60.00'), ['a' => 300, 'b' => 500, 'c' => 200]);
        $this->assertSame('60.00', MoneyBridge::sum($parts)->toDecimal());
        $this->assertSame(['18.00', '30.00', '12.00'], [$parts['a']->toDecimal(), $parts['b']->toDecimal(), $parts['c']->toDecimal()]);
        $odd = MoneyBridge::allocate(MoneyBridge::toMoney('0.10'), [1, 1, 1]); // 10 paise into 3
        $this->assertSame(10, MoneyBridge::sum($odd)->amountMinor());
    }
}
