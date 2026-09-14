<?php

namespace Marvel\Services\Accounting;

use App\Shared\Domain\ValueObject\Money;
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

    // ── P13 reports ──────────────────────────────────────────────────────────

    /** Base query: posted lines with their entry + account. */
    private function lines(): \Illuminate\Database\Query\Builder
    {
        return DB::table('acc_journal_lines as l')->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('e.status', self::POSTED);
    }

    private function applyDims($q, array $f): void
    {
        foreach (['shop_id', 'customer_id', 'order_id', 'order_item_id', 'refund_id', 'settlement_id', 'vendor_payment_id', 'shipment_id', 'tax_kind'] as $dim) {
            if (isset($f[$dim]) && $f[$dim] !== '') {
                $q->where('l.' . $dim, $f[$dim]);
            }
        }
        if (!empty($f['account'])) {
            $q->where(fn ($w) => $w->where('a.code', $f['account'])->orWhere('a.id', is_numeric($f['account']) ? (int) $f['account'] : -1));
        }
        if (!empty($f['source_type'])) {
            $q->where('e.source_type', $f['source_type']);
        }
        if (!empty($f['reference_type'])) {
            $q->where('e.reference_type', $f['reference_type']);
        }
        if (!empty($f['reference_id'])) {
            $q->where('e.reference_id', $f['reference_id']);
        }
        if (!empty($f['entry_number'])) {
            $q->where('e.entry_number', $f['entry_number']);
        }
        if (!empty($f['search'])) {
            $s = '%' . $f['search'] . '%';
            $q->where(fn ($w) => $w->where('e.description', 'like', $s)->orWhere('l.description', 'like', $s)->orWhere('e.source_key', 'like', $s));
        }
    }

    /**
     * General ledger (spec §14): paginated lines with a running balance. With an account filter the
     * running balance starts from that account's opening balance (same dimensions) before `from`.
     */
    public function generalLedger(array $f = [], int $page = 1, int $perPage = 50): array
    {
        $from = !empty($f['from']) ? Carbon::parse($f['from'])->toDateString() : null;
        $to = !empty($f['to']) ? Carbon::parse($f['to'])->toDateString() : null;
        $q = $this->lines();
        $this->applyDims($q, $f);
        if ($from) { $q->where('l.entry_date', '>=', $from); }
        if ($to) { $q->where('l.entry_date', '<=', $to); }
        $total = (clone $q)->count();
        $perPage = max(1, min(500, $perPage));
        $page = max(1, $page);
        $rows = (clone $q)->orderBy('l.entry_date')->orderBy('l.journal_entry_id')->orderBy('l.line_no')
            ->select('l.*', 'e.entry_number', 'e.source_type', 'e.source_key', 'e.reference_type', 'e.reference_id', 'e.status as entry_status', 'e.description as entry_description', 'a.code as account_code', 'a.name as account_name', 'a.normal_side')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $running = null;
        $opening = null;
        $side = null;
        if (!empty($f['account'])) {
            $acct = DB::table('acc_accounts')->where('code', $f['account'])->orWhere('id', is_numeric($f['account']) ? (int) $f['account'] : -1)->first();
            $side = $acct->normal_side ?? 'debit';
            $before = $this->lines(); $this->applyDims($before, $f);
            if ($from) { $before->where('l.entry_date', '<', $from); } else { $before->whereRaw('1 = 0'); }
            $b = $before->selectRaw('COALESCE(SUM(l.debit),0) as d, COALESCE(SUM(l.credit),0) as c')->first();
            $opening = $this->signed($side, (string) $b->d, (string) $b->c);
            // the running balance of page N starts after the rows of pages 1..N-1
            $prev = (clone $q)->orderBy('l.entry_date')->orderBy('l.journal_entry_id')->orderBy('l.line_no')->limit(($page - 1) * $perPage)->get(['l.debit', 'l.credit']);
            $running = $opening;
            foreach ($prev as $pr) { $running = $running->add($this->signed($side, (string) $pr->debit, (string) $pr->credit)); }
        }
        $out = [];
        foreach ($rows as $r) {
            $row = (array) $r;
            $row['debit'] = MoneyBridge::toMoney((string) $r->debit)->toDecimal();
            $row['credit'] = MoneyBridge::toMoney((string) $r->credit)->toDecimal();
            $row['metadata'] = $r->metadata ? json_decode($r->metadata, true) : null;
            if ($running !== null) {
                $running = $running->add($this->signed($side, (string) $r->debit, (string) $r->credit));
                $row['running_balance'] = $running->toDecimal();
            }
            $out[] = $row;
        }
        $closing = null;
        if ($opening !== null) {
            $all = (clone $q)->selectRaw('COALESCE(SUM(l.debit),0) as d, COALESCE(SUM(l.credit),0) as c')->first();
            $closing = $opening->add($this->signed($side, (string) $all->d, (string) $all->c))->toDecimal();
        }
        return ['rows' => $out, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => (int) max(1, ceil($total / $perPage)),
            'opening_balance' => $opening?->toDecimal(), 'closing_balance' => $closing, 'normal_side' => $side, 'from' => $from, 'to' => $to];
    }

    private function signed(string $side, string $debit, string $credit): Money
    {
        $d = MoneyBridge::toMoney($debit); $c = MoneyBridge::toMoney($credit);
        return $side === 'debit' ? $d->subtract($c) : $c->subtract($d);
    }

    /** P&L for a range: revenue (credit-normal) − direct costs (5xxx) − operating expenses (6xxx). */
    public function profitAndLoss(?string $from = null, ?string $to = null): array
    {
        $q = $this->lines()->whereIn('a.type', ['revenue', 'expense']);
        if ($from) { $q->where('l.entry_date', '>=', Carbon::parse($from)->toDateString()); }
        if ($to) { $q->where('l.entry_date', '<=', Carbon::parse($to)->toDateString()); }
        $rows = $q->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.normal_side')->orderBy('a.code')
            ->selectRaw('a.code, a.name, a.type, a.normal_side, COALESCE(SUM(l.debit),0) as d, COALESCE(SUM(l.credit),0) as c')->get();
        $sections = ['revenue' => [], 'direct_costs' => [], 'operating_expenses' => []];
        $sum = ['revenue' => MoneyBridge::zero(), 'direct_costs' => MoneyBridge::zero(), 'operating_expenses' => MoneyBridge::zero()];
        foreach ($rows as $r) {
            $net = $this->signed($r->normal_side, (string) $r->d, (string) $r->c); // contra-revenue (4015, debit-normal) comes out positive and is subtracted below
            $key = $r->type === 'revenue' ? 'revenue' : (str_starts_with((string) $r->code, '5') ? 'direct_costs' : 'operating_expenses');
            $amount = $r->type === 'revenue' && $r->normal_side === 'debit' ? Money::fromMinor(-$net->amountMinor()) : $net;
            $sections[$key][] = ['code' => $r->code, 'name' => $r->name, 'amount' => $amount->toDecimal()];
            $sum[$key] = $sum[$key]->add($amount);
        }
        $gross = $sum['revenue']->subtract($sum['direct_costs']);
        $net = $gross->subtract($sum['operating_expenses']);
        return ['from' => $from, 'to' => $to] + $sections + [
            'total_revenue' => $sum['revenue']->toDecimal(), 'total_direct_costs' => $sum['direct_costs']->toDecimal(), 'gross_profit' => $gross->toDecimal(),
            'total_operating_expenses' => $sum['operating_expenses']->toDecimal(), 'net_income' => $net->toDecimal(),
        ];
    }

    /** Accounts payable: vendor payables per shop (GL 2010) with open settlements + last payment; courier/DP payables. */
    public function accountsPayable(?string $asOf = null, ?AccountingConfig $config = null): array
    {
        $c = $config ?: new AccountingConfig();
        $to = $asOf ? Carbon::parse($asOf)->toDateString() : null;
        $q = $this->lines()->where('a.code', $c->accountCode('vendor_payables'))->whereNotNull('l.shop_id');
        if ($to) { $q->where('l.entry_date', '<=', $to); }
        $gl = $q->groupBy('l.shop_id')->selectRaw('l.shop_id, COALESCE(SUM(l.credit),0) - COALESCE(SUM(l.debit),0) as bal')->get()->keyBy('shop_id');
        $shopIds = $gl->keys()->all();
        $names = $shopIds && \Illuminate\Support\Facades\Schema::hasTable('shops') ? DB::table('shops')->whereIn('id', $shopIds)->pluck('name', 'id') : collect();
        $open = \Illuminate\Support\Facades\Schema::hasTable('vendor_settlements') ? DB::table('vendor_settlements')->whereIn('status', \Marvel\Services\SettlementService::OPEN)->groupBy('shop_id')->selectRaw('shop_id, COUNT(*) as n, COALESCE(SUM(remaining_payable),0) as remaining')->get()->keyBy('shop_id') : collect();
        $lastPay = \Illuminate\Support\Facades\Schema::hasTable('vendor_payments') ? DB::table('vendor_payments')->where('status', 'completed')->groupBy('shop_id')->selectRaw('shop_id, MAX(payment_date) as last_date')->get()->keyBy('shop_id') : collect();
        $rows = []; $total = MoneyBridge::zero();
        foreach ($gl as $shopId => $r) {
            $bal = MoneyBridge::toMoney((string) $r->bal);
            $total = $total->add($bal);
            $rows[] = ['shop_id' => (int) $shopId, 'shop_name' => $names[$shopId] ?? null, 'payable' => $bal->toDecimal(),
                'open_settlements' => (int) ($open[$shopId]->n ?? 0), 'awaiting_payout' => MoneyBridge::toMoney((string) ($open[$shopId]->remaining ?? 0))->toDecimal(),
                'last_payment_date' => $lastPay[$shopId]->last_date ?? null];
        }
        usort($rows, fn ($a, $b) => (float) $b['payable'] <=> (float) $a['payable']);
        return ['as_of' => $to, 'vendors' => $rows, 'total_vendor_payables' => $total->toDecimal(),
            'courier_payables' => $this->accountBalance($c->accountCode('other_current_liabilities'), $to),
            'delivery_partner_payables' => $this->accountBalance($c->accountCode('dp_payables'), $to),
            'customer_refunds_payable' => $this->accountBalance($c->accountCode('customer_refund_payable'), $to)];
    }

    /** Cash & bank: balances of bank / gateway receivable / COD receivable and their movements by source in the range. */
    public function cashAndBank(?string $from = null, ?string $to = null, ?AccountingConfig $config = null): array
    {
        $c = $config ?: new AccountingConfig();
        $out = ['from' => $from, 'to' => $to, 'accounts' => []];
        foreach (['bank' => 'Bank', 'gateway_receivable' => 'Payment gateway receivable', 'customer_receivable' => 'COD receivable', 'customer_wallet_liability' => 'Customer wallet liability', 'customer_advances' => 'Customer advances'] as $role => $label) {
            $code = $c->accountCode($role);
            $q = $this->lines()->where('a.code', $code);
            if ($from) { $q->where('l.entry_date', '>=', Carbon::parse($from)->toDateString()); }
            if ($to) { $q->where('l.entry_date', '<=', Carbon::parse($to)->toDateString()); }
            $moves = $q->groupBy('e.source_type')->selectRaw('e.source_type, COALESCE(SUM(l.debit),0) as d, COALESCE(SUM(l.credit),0) as c, COUNT(*) as n')->get()
                ->map(fn ($m) => ['source_type' => $m->source_type, 'debit' => MoneyBridge::toMoney((string) $m->d)->toDecimal(), 'credit' => MoneyBridge::toMoney((string) $m->c)->toDecimal(), 'lines' => (int) $m->n])->values()->all();
            $out['accounts'][] = ['role' => $role, 'code' => $code, 'label' => $label, 'balance' => $this->accountBalance($code, $to), 'opening' => $from ? $this->accountBalance($code, Carbon::parse($from)->subDay()->toDateString()) : null, 'movements' => $moves];
        }
        return $out;
    }

    /** Vendor statement (spec §15, §59): opening, every payable movement with running balance, closing, plus settlements + payments. */
    public function vendorStatement(int $shopId, ?string $from = null, ?string $to = null, ?AccountingConfig $config = null): array
    {
        $c = $config ?: new AccountingConfig();
        $code = $c->accountCode('vendor_payables');
        $gl = $this->generalLedger(['account' => $code, 'shop_id' => $shopId, 'from' => $from, 'to' => $to], 1, 500);
        $summary = ['sales' => MoneyBridge::zero(), 'refunds' => MoneyBridge::zero(), 'deductions' => MoneyBridge::zero(), 'adjustments' => MoneyBridge::zero(), 'payments' => MoneyBridge::zero(), 'opening_balance_entries' => MoneyBridge::zero(), 'other' => MoneyBridge::zero()];
        foreach ($gl['rows'] as $r) {
            $net = MoneyBridge::toMoney($r['credit'])->subtract(MoneyBridge::toMoney($r['debit']));
            $bucket = match ($r['source_type']) {
                'ORDER_RECOGNIZED' => 'sales', 'REVERSAL' => 'refunds', 'REFUND_POSTED' => 'refunds', 'SHIPMENT_COST' => 'deductions',
                'VENDOR_ADJUSTMENT' => 'adjustments', 'VENDOR_PAYMENT' => 'payments', 'OPENING_BALANCE' => 'opening_balance_entries', default => 'other',
            };
            $summary[$bucket] = $summary[$bucket]->add($net);
        }
        $shop = \Illuminate\Support\Facades\Schema::hasTable('shops') ? DB::table('shops')->where('id', $shopId)->first() : null;
        $settlements = \Illuminate\Support\Facades\Schema::hasTable('vendor_settlements') ? DB::table('vendor_settlements')->where('shop_id', $shopId)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))->when($to, fn ($q) => $q->where('created_at', '<=', $to . ' 23:59:59'))->orderByDesc('id')->limit(100)->get() : collect();
        $payments = \Illuminate\Support\Facades\Schema::hasTable('vendor_payments') ? DB::table('vendor_payments')->where('shop_id', $shopId)
            ->when($from, fn ($q) => $q->where('payment_date', '>=', $from))->when($to, fn ($q) => $q->where('payment_date', '<=', $to))->orderByDesc('id')->limit(100)->get() : collect();
        return [
            'shop' => ['id' => $shopId, 'name' => $shop->name ?? null, 'gstin' => $shop->gst_number ?? null], 'from' => $from, 'to' => $to,
            'opening_balance' => $gl['opening_balance'], 'closing_balance' => $gl['closing_balance'], 'rows' => $gl['rows'],
            'summary' => array_map(fn (Money $m) => $m->toDecimal(), $summary), 'settlements' => $settlements, 'payments' => $payments,
        ];
    }

    /** Dashboard tiles (spec §60): every figure is a GL aggregate. */
    public function dashboard(?string $from = null, ?string $to = null, ?AccountingConfig $config = null): array
    {
        $c = $config ?: new AccountingConfig();
        $pl = $this->profitAndLoss($from, $to);
        $bal = fn (string $role) => $this->accountBalance($c->accountCode($role), $to);
        $gst = MoneyBridge::toMoney($bal('cgst_payable'))->add(MoneyBridge::toMoney($bal('sgst_payable')))->add(MoneyBridge::toMoney($bal('igst_payable')));
        $has = fn (string $t) => \Illuminate\Support\Facades\Schema::hasTable($t);
        $pending = $has('vendor_settlements') ? DB::table('vendor_settlements')->whereIn('status', \Marvel\Services\SettlementService::OPEN)->selectRaw('COUNT(*) as n, COALESCE(SUM(remaining_payable),0) as amt')->first() : null;
        $findings = $has('acc_reconciliation_findings') ? (int) DB::table('acc_reconciliation_findings')->where('status', 'open')->count() : 0;
        $unposted = \Illuminate\Support\Facades\Schema::hasColumn('orders', 'financial_status') ? (int) DB::table('orders')->whereNull('parent_id')->whereNull('deleted_at')->where('order_status', 'order-completed')
            ->where(fn ($w) => $w->whereNull('financial_status')->orWhere('financial_status', 'unrecognized'))->when($c->cutoverDate(), fn ($q, $d) => $q->where('created_at', '>=', $d))->count() : 0;
        $flagged = \Illuminate\Support\Facades\Schema::hasColumn('orders', 'financial_status') ? (int) DB::table('orders')->where('financial_status', 'requires_reconciliation')->count() : 0;
        $period = \Marvel\Database\Models\Accounting\AccountingPeriod::forDate(Carbon::today());
        return [
            'from' => $from, 'to' => $to,
            'revenue' => $pl['total_revenue'], 'direct_costs' => $pl['total_direct_costs'], 'gross_profit' => $pl['gross_profit'], 'net_income' => $pl['net_income'],
            'vendor_payables' => $bal('vendor_payables'), 'gst_payable' => $gst->toDecimal(), 'customer_advances' => $bal('customer_advances'), 'refund_payable' => $bal('customer_refund_payable'),
            'wallet_liability' => $bal('customer_wallet_liability'), 'gateway_receivable' => $bal('gateway_receivable'), 'cod_receivable' => $bal('customer_receivable'), 'bank' => $bal('bank'), 'inventory' => $bal('inventory'),
            'pending_settlements' => ['count' => (int) ($pending->n ?? 0), 'amount' => MoneyBridge::toMoney((string) ($pending->amt ?? 0))->toDecimal()],
            'open_findings' => $findings, 'unposted_completed_orders' => $unposted, 'flagged_orders' => $flagged,
            'current_period' => ['id' => $period->id, 'start' => $period->period_start->toDateString(), 'status' => $period->status],
            'trial_balance_balanced' => $this->trialBalance()['balanced'],
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
