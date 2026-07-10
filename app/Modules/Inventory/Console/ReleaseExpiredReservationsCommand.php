<?php

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Application\InventoryService;
use Illuminate\Console\Command;

/**
 * Sweeps expired reservations back to available stock. Run on a short schedule
 * (e.g. every minute) so abandoned checkouts don't hold stock past their TTL.
 */
class ReleaseExpiredReservationsCommand extends Command
{
    protected $signature = 'inventory:release-expired {--limit=500 : Max reservations to sweep per run}';

    protected $description = 'Release inventory reservations whose TTL has expired';

    public function handle(InventoryService $inventory): int
    {
        $released = $inventory->releaseExpired((int) $this->option('limit'));
        $this->info("inventory:release-expired released {$released} reservation(s).");

        return self::SUCCESS;
    }
}
