<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Services\Accounting\AccountingConfig;

/**
 * READ-ONLY. Answers "is it safe to switch the accounting module on here?" in one place.
 *
 * Enabling accounting is a GLOBAL switch, not a refund switch: it turns on recognition, payment
 * capture, delivery costing, the vendor sub-ledger, settlement, derecognition and refunds at
 * once, for every order-status transition in the system. And `strict` defaults TRUE, which means
 * a posting failure rolls back the BUSINESS change — one unmapped account code stops order
 * completion, not just the bookkeeping.
 *
 * So the decision deserves evidence rather than nerve. Each check below is one way the cutover
 * is known to go wrong; the command changes nothing and is safe to run against production.
 *
 *   php artisan plantathome:accounting-preflight
 *
 * Exit code 0 = every blocking check passed. 1 = at least one BLOCKER. Warnings never fail.
 */
class AccountingPreflightCommand extends Command
{
    protected $signature = 'plantathome:accounting-preflight';

    protected $description = 'Read-only: report whether the accounting module can be safely enabled here';

    private array $blockers = [];
    private array $warnings = [];

    public function handle(): int
    {
        $this->line('');
        $this->info('ACCOUNTING CUTOVER PRE-FLIGHT — read-only, changes nothing');
        $this->line(str_repeat('─', 72));

        $cfg = new AccountingConfig();
        $this->currentState($cfg);
        $this->chartOfAccounts($cfg);
        $this->preCutoverOrders();
        $this->gatewayPaymentIds();
        $this->openingBalances();
        $this->settlementExposure();

        $this->line('');
        $this->line(str_repeat('─', 72));
        foreach ($this->warnings as $w) {
            $this->warn('  WARN    ' . $w);
        }
        foreach ($this->blockers as $b) {
            $this->error('  BLOCKER ' . $b);
        }
        if ($this->blockers) {
            $this->line('');
            $this->error(sprintf('NOT SAFE TO ENABLE — %d blocker(s).', count($this->blockers)));
            return self::FAILURE;
        }
        $this->line('');
        $this->info('No blockers. Enable with strict=false first, then watch accounting:reconcile for a week.');
        return self::SUCCESS;
    }

    /** What the switch reads today, and which source wins. */
    private function currentState(AccountingConfig $cfg): void
    {
        $env = env('ACCOUNTING_ENABLED');
        $row = null;
        try {
            $opts = (array) (\Marvel\Database\Models\Settings::getData()->options ?? []);
            $row = $opts['accounting']['enabled'] ?? null;
        } catch (\Throwable $e) {
            // settings unreadable — reported by the chart check below
        }

        $this->line('');
        $this->line('<comment>Switch</comment>');
        $this->line(sprintf('  env ACCOUNTING_ENABLED : %s', $env === null ? 'unset' : ($env === '' ? "'' (EMPTY — treated as unset)" : var_export($env, true))));
        $this->line(sprintf('  settings.accounting.enabled : %s', $row === null ? 'absent' : var_export((bool) $row, true)));
        $this->line(sprintf('  effective : <options=bold>%s</>', $cfg->enabled() ? 'ON' : 'OFF'));
        $this->line(sprintf('  strict : %s%s', $cfg->strict() ? 'TRUE' : 'false', $cfg->strict() ? '  ← a posting failure will roll back the business change' : ''));

        // An explicitly EMPTY env var falls through to the settings row. Having both set, with
        // different answers, is how the vendor ledger was silently disabled once before.
        if ($env !== null && $env !== '' && $row !== null) {
            $envOn = filter_var($env, FILTER_VALIDATE_BOOLEAN);
            if ($envOn !== (bool) $row) {
                $this->warnings[] = 'env and settings disagree about accounting.enabled — env wins. Set only one.';
            }
        }
        if ($cfg->enabled() && $cfg->strict()) {
            $this->warnings[] = 'strict is TRUE. Prefer false for the first two weeks after a cutover.';
        }
    }

    /** accountCode() THROWS for an unmapped role — inside a strict transaction that is a rollback. */
    private function chartOfAccounts(AccountingConfig $cfg): void
    {
        $this->line('');
        $this->line('<comment>Chart of accounts</comment>');
        if (!Schema::hasTable('acc_accounts')) {
            $this->blockers[] = 'acc_accounts does not exist — run the accounting migrations.';
            $this->line('  acc_accounts: MISSING');
            return;
        }
        $have = DB::table('acc_accounts')->pluck('code')->map(fn ($c) => (string) $c)->all();
        $missing = [];
        foreach (AccountingConfig::ROLE_DEFAULTS as $role => $code) {
            $resolved = $code;
            try {
                $resolved = $cfg->accountCode($role);
            } catch (\Throwable $e) {
                $missing[$role] = $code . ' (unmapped)';
                continue;
            }
            if (!in_array((string) $resolved, $have, true)) {
                $missing[$role] = (string) $resolved;
            }
        }
        $this->line(sprintf('  roles: %d · accounts in ledger: %d · missing: %d', count(AccountingConfig::ROLE_DEFAULTS), count($have), count($missing)));
        foreach ($missing as $role => $code) {
            $this->line(sprintf('    %-28s → %s', $role, $code));
        }
        if ($missing) {
            $this->blockers[] = sprintf('%d account role(s) do not resolve to an acc_accounts row — accountCode() throws, and in strict mode that rolls back order completion.', count($missing));
        }
    }

    /**
     * The D-A population: orders with no PAYMENT_CAPTURED journal. Refunding one of these pays
     * `postedAmount()` = 0 unless the legacy fallback is present.
     */
    private function preCutoverOrders(): void
    {
        $this->line('');
        $this->line('<comment>Pre-cutover orders (the ₹0-refund population)</comment>');
        if (!Schema::hasTable('acc_journal_entries')) {
            $this->line('  acc_journal_entries missing — skipped');
            return;
        }
        $paid = DB::table('orders')->whereNull('parent_id')->where('payment_status', 'payment-success')->count();
        $captured = DB::table('acc_journal_entries')->where('source_type', 'PAYMENT_CAPTURED')->distinct()->count('reference_id');
        $this->line(sprintf('  prepaid orders: %d · with a capture journal: %d · WITHOUT: %d', $paid, $captured, max(0, $paid - $captured)));
        $this->line('  → these refund via the legacy fallback (RefundController: journal_entry_id === null).');
        if ($paid > $captured) {
            $this->warnings[] = sprintf('%d prepaid order(s) have no capture journal. Safe ONLY with the pre-cutover fallback in place (commit a4e5fcd).', $paid - $captured);
        }
    }

    /** Without this column a gateway refund throws, whatever the credentials say. */
    private function gatewayPaymentIds(): void
    {
        $this->line('');
        $this->line('<comment>Gateway refundability</comment>');
        if (!Schema::hasColumn('orders', 'gateway_payment_id')) {
            $this->blockers[] = 'orders.gateway_payment_id is missing — no gateway refund can run.';
            $this->line('  column: MISSING');
            return;
        }
        $prepaid = DB::table('orders')->whereNull('parent_id')->where('payment_status', 'payment-success')->where('payment_gateway', 'RAZORPAY')->count();
        $withId = (clone DB::table('orders'))->whereNull('parent_id')->where('payment_status', 'payment-success')->where('payment_gateway', 'RAZORPAY')
            ->whereNotNull('gateway_payment_id')->where('gateway_payment_id', '!=', '')->count();
        $this->line(sprintf('  prepaid Razorpay orders: %d · refundable at the gateway: %d · NOT: %d', $prepaid, $withId, $prepaid - $withId));
        if ($prepaid > $withId) {
            $this->warnings[] = sprintf('%d order(s) cannot take a gateway refund. Run plantathome:backfill-gateway-payment-id.', $prepaid - $withId);
        }
    }

    /**
     * Pre-cutover orders are never posted, so without opening balances the ledger opens
     * mid-stream and vendor balances start at zero against real outstanding payables.
     *
     * These are JOURNAL ENTRIES (source_type OPENING_BALANCE, one per shop), not a table —
     * an earlier version of this check looked for an `acc_opening_balances` table that has
     * never existed in this codebase, and reported "missing — skipped" forever.
     */
    private function openingBalances(): void
    {
        $this->line('');
        $this->line('<comment>Opening balances</comment>');
        if (!Schema::hasTable('acc_journal_entries')) {
            $this->line('  acc_journal_entries missing — skipped');
            return;
        }
        $posted = DB::table('acc_journal_entries')->where('source_type', 'OPENING_BALANCE')->count();
        $vendors = Schema::hasTable('shops') ? DB::table('shops')->count() : 0;
        $this->line(sprintf('  OPENING_BALANCE entries: %d · shops: %d', $posted, $vendors));
        if ($posted === 0 && $vendors > 0) {
            $this->warnings[] = 'No OPENING_BALANCE entries. Vendor balances will open at zero against real outstanding payables — run OpeningBalanceService for the cutover date.';
        }
    }

    /** A refund posted post-cutover against a sale recognised pre-cutover nets a credit against nothing. */
    private function settlementExposure(): void
    {
        $this->line('');
        $this->line('<comment>Settlement exposure</comment>');
        if (!Schema::hasTable('vendor_settlements')) {
            $this->line('  vendor_settlements missing — skipped');
            return;
        }
        $open = DB::table('vendor_settlements')->whereIn('status', ['pending', 'approved'])->count();
        $this->line(sprintf('  open settlements: %d', $open));
        if ($open > 0) {
            $this->warnings[] = sprintf('%d open settlement(s). Dry-run the first cycle after cutover and diff before paying anyone.', $open);
        }
    }
}
