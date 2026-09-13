<?php

namespace Marvel\Services\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Accounting\AccountingAuditLog;
use Marvel\Database\Models\Accounting\AccountingPeriod;

/**
 * Accounting periods (spec §46): monthly, auto-created open; closing needs a clean
 * reconciliation of that month (or an explicit force with a reason, audited). A closed period
 * refuses posting — late system events are redirected by JournalService into the first open
 * period and flagged.
 */
class PeriodService
{
    public function __construct(private readonly ReconciliationEngine $engine = new ReconciliationEngine())
    {
    }

    /** All periods (ensures the current month exists). */
    public function list(): \Illuminate\Support\Collection
    {
        AccountingPeriod::forDate(Carbon::today());
        return AccountingPeriod::orderByDesc('period_start')->get();
    }

    public function close(int $periodId, ?string $actor = null, ?string $note = null, bool $force = false): AccountingPeriod
    {
        return DB::transaction(function () use ($periodId, $actor, $note, $force) {
            $p = AccountingPeriod::whereKey($periodId)->lockForUpdate()->firstOrFail();
            if ($p->isClosed()) {
                return $p;
            }
            if ($p->period_end->isFuture()) {
                throw new \RuntimeException('Period ' . $p->period_start->toDateString() . ' has not ended yet.');
            }
            $r = $this->engine->run($p->period_start->toDateString(), $p->period_end->toDateString(), ['journal', 'vendor', 'payment', 'gst'], $actor);
            $open = (int) DB::table('acc_reconciliation_findings')->where('status', 'open')->whereIn('kind', ['journal', 'vendor', 'payment', 'gst'])->count();
            if (($open > 0 || $r['run']->status !== 'clean') && !$force) {
                throw new \RuntimeException('Period cannot be closed: ' . $open . ' open reconciliation finding(s). Explain/resolve them first (or force with a reason).');
            }
            if ($force && !$note) {
                throw new \RuntimeException('Forcing a period close requires a reason.');
            }
            $p->status = 'closed';
            $p->closed_by = is_numeric($actor) ? (int) $actor : null;
            $p->closed_at = Carbon::now();
            $p->notes = trim(($p->notes ?? '') . "\n" . ($force ? '[FORCED] ' : '') . ($note ?? ''));
            $p->save();
            AccountingAuditLog::record('accounting_period', $p->id, 'closed', ['status' => 'open'], ['status' => 'closed', 'forced' => $force, 'open_findings' => $open], $note, $p->period_start->toDateString(), $actor);
            return $p;
        });
    }

    public function reopen(int $periodId, string $reason, ?string $actor = null): AccountingPeriod
    {
        $p = AccountingPeriod::findOrFail($periodId);
        if (!$p->isClosed()) {
            return $p;
        }
        $p->status = 'open';
        $p->closed_by = null;
        $p->closed_at = null;
        $p->notes = trim(($p->notes ?? '') . "\nReopened: " . $reason);
        $p->save();
        AccountingAuditLog::record('accounting_period', $p->id, 'reopened', ['status' => 'closed'], ['status' => 'open'], $reason, $p->period_start->toDateString(), $actor);
        return $p;
    }
}
