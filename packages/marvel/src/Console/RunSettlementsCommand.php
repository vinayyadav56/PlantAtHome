<?php

namespace Marvel\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Marvel\Database\Models\SettlementRun;
use Marvel\Database\Models\Settings;
use Marvel\Services\SettlementService;

/**
 * Sweep settle-eligible vendor ledger entries (past their T+N hold) into per-vendor
 * settlements. Scheduled daily, but a run is only CREATED when the configured payout
 * cadence is due (settings.options.settlement.cadence = daily|weekly|biweekly|monthly,
 * default daily). The T+N hold (available_at) still gates which entries are eligible —
 * cadence only governs how often payouts are generated. `--force` ignores the cadence;
 * the admin "Run settlement now" button (POST /settlements/run) is likewise always on-demand.
 */
class RunSettlementsCommand extends Command
{
    protected $signature = 'marvel:run-settlements {--force : Run regardless of the configured cadence}';

    protected $description = 'Create vendor settlements from ledger entries past their T+N hold (respecting the payout cadence)';

    public function handle(): int
    {
        if (!$this->option('force') && !$this->cadenceDue()) {
            $this->info('Settlement cadence not due yet; skipping. Use --force to override.');
            return self::SUCCESS;
        }

        $run = (new SettlementService())->run();
        $this->info("Settlement run #{$run->id}: {$run->vendor_count} vendor(s), total ₹{$run->total_amount}.");
        return self::SUCCESS;
    }

    /** Has the configured payout cadence elapsed since the last settlement run? */
    private function cadenceDue(): bool
    {
        $options = (array) (Settings::getData()->options ?? []);
        $cadence = strtolower((string) (($options['settlement']['cadence'] ?? 'daily') ?: 'daily'));

        $last = SettlementRun::max('run_date');
        if (!$last) {
            return true; // never run before
        }
        $lastDate = Carbon::parse($last);
        $now = Carbon::now();

        return match ($cadence) {
            'weekly'   => $lastDate->diffInDays($now) >= 7,
            'biweekly' => $lastDate->diffInDays($now) >= 14,
            'monthly'  => $lastDate->format('Y-m') !== $now->format('Y-m'),
            default    => true, // daily (or any unknown value) → run every day
        };
    }
}
