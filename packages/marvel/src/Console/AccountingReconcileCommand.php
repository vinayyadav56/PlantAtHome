<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Marvel\Services\Accounting\AccountingPostingService;
use Marvel\Services\Accounting\ReconciliationEngine;

/** Nightly reconciliation (spec §37-40) and the cutover gate (§16 step 5). */
class AccountingReconcileCommand extends Command
{
    protected $signature = 'accounting:reconcile
        {--from= : Period start (Y-m-d)}
        {--to= : Period end (Y-m-d)}
        {--kinds= : Comma list of journal,vendor,payment,gst,legacy,inventory (default all)}
        {--gate : Run the cutover gate; exit 1 when it fails}';

    protected $description = 'Reconcile journals vs sub-ledgers vs source records; persist findings; optionally evaluate the cutover gate';

    public function handle(): int
    {
        if (!AccountingPostingService::enabled()) {
            $this->info('accounting disabled — nothing to reconcile');
            return self::SUCCESS;
        }
        $engine = new ReconciliationEngine();
        if ($this->option('gate')) {
            $g = $engine->gate($this->option('from') ?: null, $this->option('to') ?: null, 'system:reconcile');
            foreach ($g['checks'] as $k => $v) {
                $this->line(sprintf('  %-32s %s', $k, is_bool($v) ? ($v ? 'OK' : 'FAIL') : $v));
            }
            $this->{$g['pass'] ? 'info' : 'error'}($g['pass'] ? 'GATE PASSED' : 'GATE FAILED');
            return $g['pass'] ? self::SUCCESS : self::FAILURE;
        }
        $kinds = $this->option('kinds') ? array_map('trim', explode(',', $this->option('kinds'))) : null;
        $r = $engine->run($this->option('from') ?: null, $this->option('to') ?: null, $kinds, 'system:reconcile');
        foreach ($r['all_findings'] as $f) {
            $this->warn(sprintf('  [%s] %s (%s)', $f['kind'], $f['message'], $f['difference'] ?? '-'));
        }
        $this->info(sprintf('run #%d %s · %d finding(s), %d new', $r['run']->id, $r['run']->status, count($r['all_findings']), count($r['findings'])));
        return self::SUCCESS;
    }
}
