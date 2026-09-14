<?php

namespace Marvel\Services\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Accounting\AccountingAuditLog;
use Marvel\Database\Models\Accounting\JournalEntry;

/**
 * Reconciliation (spec §37-40): every check compares two INDEPENDENT records of the same
 * money and persists a finding per difference — never auto-fixes. Findings are explained or
 * resolved by a person with a note (audited).
 *
 *   journal  every posted entry balances; trial balance + balance-sheet identity; flagged
 *            entries / orders; stale drafts; completed post-cutover orders with no journal
 *   vendor   Σ vendor sub-ledger (rows linked to a journal, not reversed) == GL 2010 per shop
 *   payment  Σ captured payment_events == GL 1020 captures per order; captured == paid_total
 *   gst      Σ order snapshot tax (recognised, not reversed) − credit notes == GL 2020/2030/2040
 *   legacy   D4: legacy balances.current_balance vs the OPENING_BALANCE journal per shop
 *   inventory placeholder until the inventory ledger (P11) posts
 *
 * gate() is the cutover / period-close gate: all of the above clean + opening balances confirmed.
 */
class ReconciliationEngine
{
    public const KINDS = ['journal', 'vendor', 'payment', 'gst', 'legacy', 'inventory'];

    public function __construct(private readonly AccountingConfig $config = new AccountingConfig(), private readonly FinancialReports $reports = new FinancialReports())
    {
    }

    /** Run the checks, persist the run + findings, return ['run' => object, 'findings' => array, 'summary' => array]. */
    public function run(?string $from = null, ?string $to = null, ?array $kinds = null, ?string $actor = null): array
    {
        $kinds = array_values(array_intersect($kinds ?: self::KINDS, self::KINDS));
        $runId = DB::table('acc_reconciliation_runs')->insertGetId([
            'kinds' => implode(',', $kinds), 'period_from' => $from, 'period_to' => $to, 'status' => 'running',
            'ran_by' => $actor ?? 'system', 'started_at' => Carbon::now(), 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
        $findings = [];
        $summary = [];
        try {
            foreach ($kinds as $k) {
                $method = 'check' . ucfirst($k);
                $r = $this->$method($from, $to);
                $summary[$k] = $r['summary'];
                foreach ($r['findings'] as $f) {
                    $findings[] = $f + ['kind' => $k];
                }
            }
            $status = $findings ? 'findings' : 'clean';
        } catch (\Throwable $e) {
            $status = 'error';
            $summary['error'] = $e->getMessage();
        }
        // Do not duplicate a finding that is still open from an earlier run (same kind/subject/difference).
        $persisted = [];
        foreach ($findings as $i => $f) {
            // still open → same finding; explained with the same difference → a person accepted it, stay quiet
            $dup = DB::table('acc_reconciliation_findings')->where('kind', $f['kind'])->where('subject_type', $f['subject_type'])
                ->where('subject_id', (string) $f['subject_id'])->whereIn('status', ['open', 'explained'])->where('difference', $f['difference'] ?? null)->orderByRaw("CASE status WHEN 'open' THEN 0 ELSE 1 END")->value('id');
            if ($dup) {
                $findings[$i]['id'] = (int) $dup;
                continue;
            }
            $id = DB::table('acc_reconciliation_findings')->insertGetId([
                'run_id' => $runId, 'kind' => $f['kind'], 'subject_type' => $f['subject_type'], 'subject_id' => (string) $f['subject_id'],
                'expected' => $f['expected'] ?? null, 'actual' => $f['actual'] ?? null, 'difference' => $f['difference'] ?? null,
                'message' => mb_substr($f['message'], 0, 500), 'status' => 'open', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
            ]);
            $findings[$i]['id'] = (int) $id;
            $persisted[] = $findings[$i];
        }
        DB::table('acc_reconciliation_runs')->where('id', $runId)->update([
            'status' => $status, 'findings_count' => count($findings), 'summary' => json_encode($summary), 'finished_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
        AccountingAuditLog::record('reconciliation_run', $runId, $status, null, ['findings' => count($findings), 'kinds' => $kinds], null, null, $actor);
        return ['run' => DB::table('acc_reconciliation_runs')->where('id', $runId)->first(), 'findings' => $persisted, 'all_findings' => $findings, 'summary' => $summary];
    }

    /** The cutover / period-close gate (spec §16 step 5): pass only when EVERY check is clean. */
    public function gate(?string $from = null, ?string $to = null, ?string $actor = null): array
    {
        $r = $this->run($from, $to, ['journal', 'vendor', 'payment', 'gst'], $actor);
        $openFindings = (int) DB::table('acc_reconciliation_findings')->where('status', 'open')->whereIn('kind', ['journal', 'vendor', 'payment', 'gst'])->count();
        $unconfirmedOpening = JournalEntry::where('source_type', 'OPENING_BALANCE')->where('requires_reconciliation', true)->count();
        $tb = $this->reports->trialBalance();
        $bs = $this->reports->balanceSheet();
        $checks = [
            'trial_balance_balanced'     => $tb['balanced'],
            'balance_sheet_balanced'     => $bs['balanced'],
            'open_findings'              => $openFindings,
            'unconfirmed_opening_balances' => $unconfirmedOpening,
            'run_status'                 => $r['run']->status,
        ];
        $pass = $tb['balanced'] && $bs['balanced'] && $openFindings === 0 && $unconfirmedOpening === 0 && $r['run']->status === 'clean';
        return ['pass' => $pass, 'checks' => $checks, 'run_id' => $r['run']->id, 'summary' => $r['summary']];
    }

    /** A person explains or resolves a finding — with a note, audited. Never deletes. */
    public function resolve(int $findingId, string $status, string $note, ?string $actor = null): object
    {
        if (!in_array($status, ['explained', 'resolved', 'open'], true)) {
            throw new \InvalidArgumentException('status must be explained, resolved or open');
        }
        $f = DB::table('acc_reconciliation_findings')->where('id', $findingId)->first();
        if (!$f) {
            throw new \RuntimeException('Finding #' . $findingId . ' not found.');
        }
        DB::table('acc_reconciliation_findings')->where('id', $findingId)->update([
            'status' => $status, 'note' => $note, 'resolved_by' => $status === 'open' ? null : ($actor ?? 'system'),
            'resolved_at' => $status === 'open' ? null : Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
        AccountingAuditLog::record('reconciliation_finding', $findingId, $status, ['status' => $f->status], ['status' => $status], $note, $f->kind . ':' . $f->subject_type . ':' . $f->subject_id, $actor);
        return DB::table('acc_reconciliation_findings')->where('id', $findingId)->first();
    }

    // ── checks ───────────────────────────────────────────────────────────────

    private function checkJournal(?string $from, ?string $to): array
    {
        $f = [];
        // (a) any posted/reversed entry whose lines do not balance (the service forbids it; this catches DB-level tampering)
        $unbalanced = DB::table('acc_journal_lines as l')->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('e.status', ['posted', 'reversed'])->groupBy('e.id', 'e.entry_number')
            ->havingRaw('ROUND(SUM(l.debit) - SUM(l.credit), 2) <> 0')->selectRaw('e.id, e.entry_number, SUM(l.debit) as d, SUM(l.credit) as c')->get();
        foreach ($unbalanced as $u) {
            $f[] = ['subject_type' => 'journal_entry', 'subject_id' => $u->id, 'expected' => $this->dec($u->d), 'actual' => $this->dec($u->c), 'difference' => $this->diff($u->d, $u->c), 'message' => 'Journal ' . $u->entry_number . ' does not balance'];
        }
        // (b)(c) identities
        $tb = $this->reports->trialBalance($from, $to);
        if (!$tb['balanced']) {
            $f[] = ['subject_type' => 'ledger', 'subject_id' => 'trial_balance', 'expected' => $tb['total_debit'], 'actual' => $tb['total_credit'], 'difference' => $this->diff($tb['total_debit'], $tb['total_credit']), 'message' => 'Trial balance does not balance'];
        }
        $bs = $this->reports->balanceSheet($to);
        if (!$bs['balanced']) {
            $f[] = ['subject_type' => 'ledger', 'subject_id' => 'balance_sheet', 'expected' => $bs['total_assets'], 'actual' => $bs['total_liabilities'], 'difference' => $bs['difference'], 'message' => 'Assets ≠ liabilities + equity'];
        }
        // (d) flagged entries (rounding residual over tolerance, late-period redirect, unconfirmed opening balances)
        foreach (JournalEntry::where('requires_reconciliation', true)->where('source_type', '!=', 'OPENING_BALANCE')->get(['id', 'entry_number', 'source_key', 'status', 'description']) as $e) {
            $f[] = ['subject_type' => 'journal_entry', 'subject_id' => $e->id, 'difference' => null, 'message' => 'Journal ' . ($e->entry_number ?? '#' . $e->id) . ' (' . $e->source_key . ') requires reconciliation' . ($e->status === 'draft' ? ' — saved as DRAFT' : '')];
        }
        // (e) drafts older than a day that are not the flagged ones above
        foreach (JournalEntry::where('status', 'draft')->where('requires_reconciliation', false)->where('created_at', '<', Carbon::now()->subDay())->get(['id', 'source_key']) as $e) {
            $f[] = ['subject_type' => 'journal_entry', 'subject_id' => $e->id, 'difference' => null, 'message' => 'Draft journal #' . $e->id . ' (' . $e->source_key . ') older than 24h'];
        }
        // (f) orders flagged by the posting service, and completed post-cutover parents with no recognition journal
        if (Schema::hasColumn('orders', 'financial_status')) {
            foreach (DB::table('orders')->where('financial_status', 'requires_reconciliation')->whereNull('deleted_at')->get(['id', 'tracking_number']) as $o) {
                $f[] = ['subject_type' => 'order', 'subject_id' => $o->id, 'difference' => null, 'message' => 'Order ' . $o->tracking_number . ' is flagged requires_reconciliation'];
            }
            $since = $from ?: $this->config->cutoverDate();
            if ($since) { // pre-cutover history is never posted (D4) — only sweep from the cutover / window start
                $q = DB::table('orders')->whereNull('parent_id')->whereNull('deleted_at')->where('order_status', 'order-completed')
                    ->where(fn ($w) => $w->whereNull('financial_status')->orWhere('financial_status', 'unrecognized'))->where('created_at', '>=', $since);
                foreach ($q->limit(200)->get(['id', 'tracking_number']) as $o) {
                    $f[] = ['subject_type' => 'order', 'subject_id' => $o->id, 'difference' => null, 'message' => 'Completed order ' . $o->tracking_number . ' has no recognition journal (run accounting:post-pending)'];
                }
            }
        }
        return ['findings' => $f, 'summary' => ['unbalanced_entries' => $unbalanced->count(), 'trial_balance' => $tb['balanced'], 'balance_sheet' => $bs['balanced'], 'findings' => count($f)]];
    }

    private function checkVendor(?string $from, ?string $to): array
    {
        $f = [];
        if (!Schema::hasTable('vendor_ledger_entries')) {
            return ['findings' => [], 'summary' => ['skipped' => 'no vendor ledger']];
        }
        $code = $this->config->accountCode('vendor_payables');
        $gl = DB::table('acc_journal_lines as l')->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('e.status', ['posted', 'reversed'])->where('a.code', $code)->whereNotNull('l.shop_id')
            ->groupBy('l.shop_id')->selectRaw('l.shop_id, COALESCE(SUM(l.credit),0) - COALESCE(SUM(l.debit),0) as bal')->pluck('bal', 'shop_id');
        // sub-ledger: only rows written by the accounting engine (linked to a journal); pre-cutover legacy rows are not in the GL
        $sl = DB::table('vendor_ledger_entries')->whereNotNull('journal_entry_id')->where('status', '!=', 'reversed')
            ->groupBy('shop_id')->selectRaw('shop_id, COALESCE(SUM(amount),0) as bal')->pluck('bal', 'shop_id');
        // 2010 lines with no shop dimension belong to nobody's sub-ledger — they must net to zero
        $orphan = MoneyBridge::toMoney((string) DB::table('acc_journal_lines as l')->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('e.status', ['posted', 'reversed'])->where('a.code', $code)->whereNull('l.shop_id')->selectRaw('COALESCE(SUM(l.credit),0) - COALESCE(SUM(l.debit),0) as bal')->value('bal'));
        if (!$orphan->isZero()) {
            $f[] = ['subject_type' => 'account', 'subject_id' => $code, 'expected' => '0.00', 'actual' => $orphan->toDecimal(), 'difference' => $orphan->toDecimal(), 'message' => 'GL ' . $code . ' carries ' . $orphan->toDecimal() . ' with no vendor dimension'];
        }
        $shops = array_unique(array_merge(array_keys($gl->all()), array_keys($sl->all())));
        foreach ($shops as $shopId) {
            $g = MoneyBridge::toMoney((string) ($gl[$shopId] ?? 0));
            $s = MoneyBridge::toMoney((string) ($sl[$shopId] ?? 0));
            if (!$g->equals($s)) {
                $f[] = ['subject_type' => 'shop', 'subject_id' => $shopId, 'expected' => $g->toDecimal(), 'actual' => $s->toDecimal(), 'difference' => $s->subtract($g)->toDecimal(), 'message' => 'Vendor sub-ledger ' . $s->toDecimal() . ' ≠ GL ' . $code . ' ' . $g->toDecimal() . ' for shop #' . $shopId];
            }
        }
        return ['findings' => $f, 'summary' => ['shops_checked' => count($shops), 'mismatched' => count($f)]];
    }

    private function checkPayment(?string $from, ?string $to): array
    {
        $f = [];
        if (!Schema::hasTable('payment_events')) {
            return ['findings' => [], 'summary' => ['skipped' => 'no payment_events']];
        }
        $code = $this->config->accountCode('gateway_receivable');
        $captured = DB::table('payment_events')->whereNotNull('order_id')->whereIn('status', ['captured', 'processed', 'success'])
            ->groupBy('order_id')->selectRaw('order_id, COALESCE(SUM(amount),0) as amt')->pluck('amt', 'order_id');
        $gl = DB::table('acc_journal_lines as l')->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.source_type', 'PAYMENT_CAPTURED')->whereIn('e.status', ['posted', 'reversed'])->where('a.code', $code)->whereNotNull('l.order_id')
            ->groupBy('l.order_id')->selectRaw('l.order_id, COALESCE(SUM(l.debit),0) as amt')->pluck('amt', 'order_id');
        $orderIds = array_unique(array_merge(array_keys($captured->all()), array_keys($gl->all())));
        $orders = $orderIds ? DB::table('orders')->whereIn('id', $orderIds)->get(['id', 'tracking_number', 'paid_total', 'payment_gateway', 'captured_amount'])->keyBy('id') : collect();
        foreach ($orderIds as $oid) {
            $c = MoneyBridge::toMoney((string) ($captured[$oid] ?? 0));
            $g = MoneyBridge::toMoney((string) ($gl[$oid] ?? 0));
            $o = $orders[$oid] ?? null;
            $tn = $o->tracking_number ?? ('#' . $oid);
            if (!$c->equals($g)) {
                $f[] = ['subject_type' => 'order', 'subject_id' => $oid, 'expected' => $c->toDecimal(), 'actual' => $g->toDecimal(), 'difference' => $g->subtract($c)->toDecimal(), 'message' => 'Captured events ' . $c->toDecimal() . ' ≠ GL ' . $code . ' captures ' . $g->toDecimal() . ' for order ' . $tn];
            }
            if ($o && !$c->isZero()) {
                // prepaid: the gateway capture must equal what the order says the customer paid (net of any wallet part)
                $wallet = Schema::hasTable('order_wallet_points') ? MoneyBridge::toMoney((string) DB::table('order_wallet_points')->where('order_id', $oid)->sum('amount')) : MoneyBridge::zero();
                $expected = MoneyBridge::toMoney((string) $o->paid_total)->subtract($wallet);
                if (!$expected->equals($c)) {
                    $f[] = ['subject_type' => 'order', 'subject_id' => $oid, 'expected' => $expected->toDecimal(), 'actual' => $c->toDecimal(), 'difference' => $c->subtract($expected)->toDecimal(), 'message' => 'Captured ' . $c->toDecimal() . ' ≠ paid_total ' . $expected->toDecimal() . ' for order ' . $tn];
                }
            }
        }
        return ['findings' => $f, 'summary' => ['orders_checked' => count($orderIds), 'mismatched' => count($f)]];
    }

    private function checkGst(?string $from, ?string $to): array
    {
        $f = [];
        if (!Schema::hasColumn('orders', 'cgst_amount')) {
            return ['findings' => [], 'summary' => ['skipped' => 'no tax snapshot']];
        }
        // expected: snapshot tax of orders whose recognition journal stands (posted, not reversed) − credit notes
        $rec = DB::table('acc_journal_entries as e')->join('orders as o', DB::raw("e.source_key"), '=', DB::raw("'ORDER_RECOGNIZED:' || o.id"));
        // The || concat is sqlite; MySQL needs CONCAT — build per driver.
        $driver = DB::connection()->getDriverName();
        $concat = $driver === 'sqlite' ? "'ORDER_RECOGNIZED:' || o.id" : "CONCAT('ORDER_RECOGNIZED:', o.id)";
        $snap = DB::table('orders as o')->join('acc_journal_entries as e', 'e.source_key', '=', DB::raw($concat))
            ->where('e.status', 'posted')->selectRaw('COALESCE(SUM(o.cgst_amount),0) as cgst, COALESCE(SUM(o.sgst_amount),0) as sgst, COALESCE(SUM(o.igst_amount),0) as igst')->first();
        $cn = Schema::hasTable('credit_notes')
            ? DB::table('credit_notes')->whereNotNull('journal_entry_id')->selectRaw('COALESCE(SUM(cgst_amount),0) as cgst, COALESCE(SUM(sgst_amount),0) as sgst, COALESCE(SUM(igst_amount),0) as igst')->first()
            : (object) ['cgst' => 0, 'sgst' => 0, 'igst' => 0];
        foreach (['cgst' => 'cgst_payable', 'sgst' => 'sgst_payable', 'igst' => 'igst_payable'] as $kind => $role) {
            $expected = MoneyBridge::toMoney((string) $snap->$kind)->subtract(MoneyBridge::toMoney((string) $cn->$kind));
            $actual = MoneyBridge::toMoney($this->reports->accountBalance($this->config->accountCode($role)));
            if (!$expected->equals($actual)) {
                $f[] = ['subject_type' => 'tax_kind', 'subject_id' => $kind, 'expected' => $expected->toDecimal(), 'actual' => $actual->toDecimal(), 'difference' => $actual->subtract($expected)->toDecimal(), 'message' => strtoupper($kind) . ' snapshot net of credit notes ' . $expected->toDecimal() . ' ≠ GL ' . $actual->toDecimal()];
            }
        }
        return ['findings' => $f, 'summary' => ['snapshot' => ['cgst' => $this->dec($snap->cgst), 'sgst' => $this->dec($snap->sgst), 'igst' => $this->dec($snap->igst)], 'credit_notes' => ['cgst' => $this->dec($cn->cgst), 'sgst' => $this->dec($cn->sgst), 'igst' => $this->dec($cn->igst)], 'mismatched' => count($f)]];
    }

    /** D4: the legacy `balances` figure each vendor was owed vs the opening balance entered at cutover. */
    private function checkLegacy(?string $from, ?string $to): array
    {
        $f = [];
        if (!Schema::hasTable('balances')) {
            return ['findings' => [], 'summary' => ['skipped' => 'no legacy balances']];
        }
        $legacy = DB::table('balances')->whereNotNull('shop_id')->pluck('current_balance', 'shop_id');
        $opening = DB::table('acc_journal_lines as l')->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.source_type', 'OPENING_BALANCE')->whereIn('e.status', ['posted'])->where('a.code', $this->config->accountCode('vendor_payables'))
            ->groupBy('l.shop_id')->selectRaw('l.shop_id, COALESCE(SUM(l.credit),0) - COALESCE(SUM(l.debit),0) as bal')->pluck('bal', 'shop_id');
        $checked = 0;
        foreach ($legacy as $shopId => $bal) {
            $l = MoneyBridge::toMoney((string) ($bal ?? 0));
            if ($l->isZero() && !isset($opening[$shopId])) {
                continue;
            }
            $checked++;
            $o = MoneyBridge::toMoney((string) ($opening[$shopId] ?? 0));
            if (!$l->equals($o)) {
                $f[] = ['subject_type' => 'shop', 'subject_id' => $shopId, 'expected' => $l->toDecimal(), 'actual' => $o->toDecimal(), 'difference' => $o->subtract($l)->toDecimal(), 'message' => isset($opening[$shopId]) ? 'Opening balance ' . $o->toDecimal() . ' ≠ legacy balance ' . $l->toDecimal() . ' for shop #' . $shopId : 'Shop #' . $shopId . ' has a legacy balance of ' . $l->toDecimal() . ' but no opening balance entry'];
            }
        }
        return ['findings' => $f, 'summary' => ['shops_checked' => $checked, 'mismatched' => count($f)]];
    }

    private function checkInventory(?string $from, ?string $to): array
    {
        if (!Schema::hasTable('inventory_valuations')) {
            return ['findings' => [], 'summary' => ['skipped' => 'inventory ledger not enabled']];
        }
        $code = $this->config->accountCode('inventory');
        $gl = MoneyBridge::toMoney($this->reports->accountBalance($code));
        $val = MoneyBridge::toMoney((string) DB::table('inventory_valuations')->selectRaw('COALESCE(SUM(total_value),0) as v')->value('v'));
        $f = [];
        if (!$gl->equals($val)) {
            $f[] = ['subject_type' => 'account', 'subject_id' => $code, 'expected' => $val->toDecimal(), 'actual' => $gl->toDecimal(), 'difference' => $gl->subtract($val)->toDecimal(), 'message' => 'Inventory valuation ' . $val->toDecimal() . ' ≠ GL ' . $code . ' ' . $gl->toDecimal()];
        }
        return ['findings' => $f, 'summary' => ['valuation' => $val->toDecimal(), 'gl' => $gl->toDecimal()]];
    }

    private function dec($v): string
    {
        return MoneyBridge::toMoney((string) ($v ?? 0))->toDecimal();
    }

    private function diff($a, $b): string
    {
        return MoneyBridge::toMoney((string) ($a ?? 0))->subtract(MoneyBridge::toMoney((string) ($b ?? 0)))->toDecimal();
    }
}
