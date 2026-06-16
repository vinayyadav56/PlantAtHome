<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Marvel\Services\SettlementService;

/**
 * Sweep settle-eligible vendor ledger entries (past their T+N hold) into per-vendor
 * settlements. Runs daily on a schedule, or on demand from the admin settlement screen.
 */
class RunSettlementsCommand extends Command
{
    protected $signature = 'marvel:run-settlements';

    protected $description = 'Create vendor settlements from ledger entries past their T+N hold';

    public function handle(): int
    {
        $run = (new SettlementService())->run();
        $this->info("Settlement run #{$run->id}: {$run->vendor_count} vendor(s), total ₹{$run->total_amount}.");
        return self::SUCCESS;
    }
}
