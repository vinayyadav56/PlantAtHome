<?php

namespace App\Console\Commands;

use App\Shared\Events\OutboxRelay;
use Illuminate\Console\Command;
use Throwable;

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

        // Stop cleanly on SIGTERM/SIGINT (e.g. container shutdown) so an
        // in-flight batch isn't cut mid-transaction.
        $running = true;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = function () use (&$running) {
                $running = false;
            };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }

        while ($running) {
            try {
                $count = $relay->relay($limit);
                if ($count > 0) {
                    $this->line('['.now()->toTimeString()."] delivered {$count}");
                }
            } catch (Throwable $e) {
                // A transient error (e.g. DB blip) must not kill the daemon.
                $this->error('['.now()->toTimeString().'] relay pass failed: '.$e->getMessage());
                report($e);
            }
            sleep($sleep);
        }

        $this->info('outbox:relay worker stopped.');

        return self::SUCCESS;
    }
}
