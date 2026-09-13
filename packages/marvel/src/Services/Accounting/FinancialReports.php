<?php

namespace Marvel\Services\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Report queries over posted journal lines — DB aggregation only (spec §59). Trial balance
 * and balance sheet ship with the foundation because they are the invariants every later
 * phase is tested against; GL paging, P&L, AP, cash/bank and tax ledger extend this class.
 * Amounts are returned as decimal strings; identities are checked in integer paise.
 */
class FinancialReports
{
    private const POSTED = ['posted', 'reversed']; // a reversed entry's lines still stand; the reversal nets them

    /** Per-account debit/credit totals for a date range. */
    public function trialBalance(?string $from = null, ?string $to = null): array
    {
        $q = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('e.status', self::POSTED)
            ->selectRaw('a.id as account_id, a.code, a.name, a.type, a.normal_side, COALESCE(SUM(l.debit),0) as debit, COALESCE(SUM(l.credit),0) as credit')
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.normal_side')
            ->orderBy('a.code');
        if ($from) {
            $q->where('l.entry_date', '>=', Carbon::parse($from)->toDateString());
        }
        if ($to) {
            $q->where('l.entry_date', '<=', Carbon::parse($to)->toDateString());
        }
        $rows = [];
        $td = MoneyBridge::zero();
        $tc = MoneyBridge::zero();
        foreach ($q->get() as $r) {
            $d = MoneyBridge::toMoney((string) $r->debit);
            $c = MoneyBridge::toMoney((string) $r->credit);
            $td = $td->add($d);
            $tc = $tc->add($c);
            $net = $r->normal_side === 'debit' ? $d->subtract($c) : $c->subtract($d);
            $rows[] = [
                'account_id' => (int) $r->account_id, 'code' => $r->code, 'name' => $r->name, 'type' => $r->type,
                'debit' => $d->toDecimal(), 'credit' => $c->toDecimal(), 'balance' => $net->toDecimal(),
            ];
        }
        return [
            'from' => $from, 'to' => $to, 'rows' => $rows,
            'total_debit' => $td->toDecimal(), 'total_credit' => $tc->toDecimal(),
            'balanced' => $td->equals($tc),
        ];
    }

    /** Balance sheet as of a date: assets = liabilities + equity (equity includes net income to date). */
    public function balanceSheet(?string $asOf = null): array
    {
        $asOf = Carbon::parse($asOf ?? Carbon::today())->toDateString();
        $totals = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('e.status', self::POSTED)
            ->where('l.entry_date', '<=', $asOf)
            ->selectRaw('a.type, a.code, a.name, COALESCE(SUM(l.debit),0) as debit, COALESCE(SUM(l.credit),0) as credit')
            ->groupBy('a.type', 'a.code', 'a.name')
            ->orderBy('a.code')
            ->get();

        $sections = ['asset' => [], 'liability' => [], 'equity' => []];
        $sum = ['asset' => MoneyBridge::zero(), 'liability' => MoneyBridge::zero(), 'equity' => MoneyBridge::zero(), 'revenue' => MoneyBridge::zero(), 'expense' => MoneyBridge::zero()];
        foreach ($totals as $r) {
            $d = MoneyBridge::toMoney((string) $r->debit);
            $c = MoneyBridge::toMoney((string) $r->credit);
            $net = in_array($r->type, ['asset', 'expense'], true) ? $d->subtract($c) : $c->subtract($d);
            $sum[$r->type] = $sum[$r->type]->add($net);
            if (isset($sections[$r->type])) {
                $sections[$r->type][] = ['code' => $r->code, 'name' => $r->name, 'balance' => $net->toDecimal()];
            }
        }
        $netIncome = $sum['revenue']->subtract($sum['expense']);
        $equityTotal = $sum['equity']->add($netIncome);
        $rhs = $sum['liability']->add($equityTotal);
        return [
            'as_of' => $asOf,
            'assets' => $sections['asset'], 'liabilities' => $sections['liability'], 'equity' => $sections['equity'],
            'total_assets' => $sum['asset']->toDecimal(),
            'total_liabilities' => $sum['liability']->toDecimal(),
            'net_income_to_date' => $netIncome->toDecimal(),
            'total_equity' => $equityTotal->toDecimal(),
            'balanced' => $sum['asset']->equals($rhs),
            'difference' => $sum['asset']->subtract($rhs)->toDecimal(),
        ];
    }

    /** Signed balance of one account (by code) as of a date, in its normal side. */
    public function accountBalance(string $code, ?string $asOf = null, ?int $shopId = null): string
    {
        $q = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('e.status', self::POSTED)
            ->where('a.code', $code);
        if ($asOf) {
            $q->where('l.entry_date', '<=', Carbon::parse($asOf)->toDateString());
        }
        if ($shopId !== null) {
            $q->where('l.shop_id', $shopId);
        }
        $r = $q->selectRaw('MAX(a.normal_side) as side, COALESCE(SUM(l.debit),0) as debit, COALESCE(SUM(l.credit),0) as credit')->first();
        $d = MoneyBridge::toMoney((string) ($r->debit ?? 0));
        $c = MoneyBridge::toMoney((string) ($r->credit ?? 0));
        return (($r->side ?? 'debit') === 'debit' ? $d->subtract($c) : $c->subtract($d))->toDecimal();
    }
}
