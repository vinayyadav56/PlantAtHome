<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Marvel\Services\ReconciliationService;

/**
 * Money-parity reconciliation between the legacy per-order commission (balances) and the
 * new vendor ledger — the gate before the P4 cutover. Run it over a settlement cycle once
 * the ledger is enabled (settings.options.settlement.enabled); `coverage_missing` must be 0
 * (every legacy-credited order has a matching ledger entry) before retiring the legacy path.
 */
class ReconcileSettlementsCommand extends Command
{
    protected $signature = 'marvel:reconcile-settlements {--from= : ISO date/time} {--to= : ISO date/time}';

    protected $description = 'Compare legacy commission vs vendor ledger (cutover gate: coverage_missing=0)';

    public function handle(): int
    {
        $report = (new ReconciliationService())->report($this->option('from'), $this->option('to'));
        $o = $report['overall'];

        $this->line('');
        $this->info('Settlement reconciliation' . ($report['ledger_enabled'] ? '' : '  [LEDGER FLAG OFF — no new entries are being recorded]'));
        $this->line("  Period:            " . ($report['period']['from'] ?? 'all') . ' → ' . ($report['period']['to'] ?? 'now'));
        $this->line("  Legacy earnings:   ₹{$o['legacy_earnings']}  ({$o['legacy_orders']} completed suborders)");
        $this->line("  Ledger earnings:   ₹{$o['ledger_vendor_earning']}  ({$o['ledger_orders']} ledger sales)");
        $this->line("  Coverage missing:  {$o['coverage_missing']}  (legacy-credited orders with NO ledger entry)");
        $this->line("  Coverage extra:    {$o['coverage_extra']}");

        if ($o['cutover_safe']) {
            $this->info('  ✓ CUTOVER SAFE — every legacy-credited order has a matching ledger entry.');
        } else {
            $this->warn('  ✗ NOT SAFE — ' . $o['coverage_missing'] . ' order(s) lack a ledger entry. Sample: ' . implode(', ', $o['sample_missing_order_ids']));
            $this->line('    (Expected if some orders completed BEFORE the ledger flag was turned on.)');
        }
        $this->line('');
        return self::SUCCESS;
    }
}
