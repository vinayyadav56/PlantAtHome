<?php

namespace App\Console\Commands;

use App\Shared\Events\OutboxRelay;
use Illuminate\Console\Command;

/**
 * Drains the transactional outbox, delivering pending domain events to their
 * subscribers. Run `--once` for a single pass (CI / cron) or as a long-running
 * worker (supervisor) that polls every `--sleep` seconds. This is the relay in
 * Section 9's transactional-outbox pattern.
 */
class OutboxRelayCommand extends Command
{
    protected $signature = 'outbox:relay {--once : Process one batch and exit} {--limit=100 : Max events per batch} {--sleep=1 : Seconds between passes when running continuously}';

    protected $description = 'Deliver pending domain events from the outbox to their subscribers';

    public function handle(OutboxRelay $relay): int
    {
        $limit = (int) $this->option('limit');

        if ($this->option('once')) {
            $count = $relay->relay($limit);
            $this->info("outbox:relay delivered {$count} event(s).");

            return self::SUCCESS;
        }

        $sleep = max(1, (int) $this->option('sleep'));
        $this->info("outbox:relay worker started (limit={$limit}, sleep={$sleep}s). Ctrl-C to stop.");
        while (true) {
            $count = $relay->relay($limit);
            if ($count > 0) {
                $this->line('['.now()->toTimeString()."] delivered {$count}");
            }
            sleep($sleep);
        }
    }
}
