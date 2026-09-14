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

    /**
     * GST ledger (spec §16-18): output tax by kind and by HSN/rate, straight from journal lines
     * carrying tax dims — recognitions credit, credit notes debit, so every figure is net.
     */
    public function taxLedger(?string $from = null, ?string $to = null, ?AccountingConfig $config = null): array
    {
        $c = $config ?: new AccountingConfig();
        $base = fn () => DB::table('acc_journal_lines as l')->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('e.status', self::POSTED)
            ->when($from, fn ($q) => $q->where('l.entry_date', '>=', Carbon::parse($from)->toDateString()))
            ->when($to, fn ($q) => $q->where('l.entry_date', '<=', Carbon::parse($to)->toDateString()));
        $tax = $base()->whereNotNull('l.tax_kind')->groupBy('l.tax_kind', 'l.hsn_code', 'l.tax_rate')
            ->selectRaw('l.tax_kind, l.hsn_code, l.tax_rate, COALESCE(SUM(l.credit),0) - COALESCE(SUM(l.debit),0) as net, COUNT(DISTINCT l.order_id) as orders')->get();
        $revenueCodes = [$c->accountCode('product_sales'), $c->accountCode('delivery_revenue')];
        $taxable = $base()->whereIn('a.code', $revenueCodes)->groupBy('l.hsn_code', 'l.tax_rate')
            ->selectRaw('l.hsn_code, l.tax_rate, COALESCE(SUM(l.credit),0) - COALESCE(SUM(l.debit),0) as net')->get();
        $rows = [];
        $key = fn ($hsn, $rate) => ($hsn ?? '') . '|' . ($rate === null ? '' : number_format((float) $rate, 2, '.', ''));
        foreach ($taxable as $t) {
            $rows[$key($t->hsn_code, $t->tax_rate)] = ['hsn_code' => $t->hsn_code ?: null, 'tax_rate' => $t->tax_rate === null ? null : number_format((float) $t->tax_rate, 2, '.', ''), 'taxable' => MoneyBridge::toMoney((string) $t->net)->toDecimal(), 'cgst' => '0.00', 'sgst' => '0.00', 'igst' => '0.00', 'orders' => 0];
        }
        $totals = ['cgst' => MoneyBridge::zero(), 'sgst' => MoneyBridge::zero(), 'igst' => MoneyBridge::zero()];
        foreach ($tax as $t) {
            $k = $key($t->hsn_code, $t->tax_rate);
            $rows[$k] ??= ['hsn_code' => $t->hsn_code ?: null, 'tax_rate' => $t->tax_rate === null ? null : number_format((float) $t->tax_rate, 2, '.', ''), 'taxable' => '0.00', 'cgst' => '0.00', 'sgst' => '0.00', 'igst' => '0.00', 'orders' => 0];
            $net = MoneyBridge::toMoney((string) $t->net);
            $rows[$k][$t->tax_kind] = MoneyBridge::toMoney($rows[$k][$t->tax_kind])->add($net)->toDecimal();
            $rows[$k]['orders'] = max($rows[$k]['orders'], (int) $t->orders);
            $totals[$t->tax_kind] = $totals[$t->tax_kind]->add($net);
        }
        ksort($rows);
        $liability = $totals['cgst']->add($totals['sgst'])->add($totals['igst']);
        return [
            'from' => $from, 'to' => $to, 'rows' => array_values($rows),
            'totals' => ['taxable' => MoneyBridge::sum(array_map(fn ($r) => MoneyBridge::toMoney($r['taxable']), $rows))->toDecimal(), 'cgst' => $totals['cgst']->toDecimal(), 'sgst' => $totals['sgst']->toDecimal(), 'igst' => $totals['igst']->toDecimal(), 'output_tax' => $liability->toDecimal()],
            'gl_balances' => ['cgst' => $this->accountBalance($c->accountCode('cgst_payable'), $to), 'sgst' => $this->accountBalance($c->accountCode('sgst_payable'), $to), 'igst' => $this->accountBalance($c->accountCode('igst_payable'), $to)],
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
