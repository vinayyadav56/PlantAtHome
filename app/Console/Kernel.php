<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();

        // Flush lapsed vendor price-sheet windows from the city-availability projection.
        $schedule->command('marvel:recompute-city-availability')->dailyAt('03:30')->withoutOverlapping();

        // Settle vendor earnings past their T+N hold into per-vendor settlements.
        // One retention story for every log/audit table (request_logs days come from
        // the admin setting). Slotted between the 03:30 and 04:00 heavy jobs.
        $schedule->command('logs:prune')->dailyAt('03:45')->withoutOverlapping(30);
        // Geo enrichment for the logs drawer: batch-resolves DISTINCT recent IPs;
        // request path never does lookups. 20-min scan > 10-min cadence = overlap
        // margin so an IP seen during a slow sweep is not missed.
        $schedule->command('logs:enrich-ips')->everyTenMinutes()->withoutOverlapping(5);

        $schedule->command('marvel:run-settlements')->dailyAt('04:00')->withoutOverlapping();

        // Re-track open courier shipments + re-apply status (recovers missed/failed webhooks).
        // No-op unless courier is enabled, so it's safe to always schedule.

        // v2 Inventory (Phase 5): return stock held by abandoned checkouts whose
        // reservation TTL has lapsed. No-op when nothing is expired.
        $schedule->command('inventory:release-expired')->everyMinute()->withoutOverlapping();

        // v2 outbox relay (Phase 0): drain pending domain events to subscribers.
        $schedule->command('outbox:relay --once')->everyMinute()->withoutOverlapping();

        // v2 Marketing: launch campaigns whose scheduled time has arrived. Only
        // creates runs + enqueues jobs (never sends inline), so it's fast.
        $schedule->command('marketing:dispatch-due')->everyMinute()->withoutOverlapping();

        // Batch AI image generation: recover stalled rows / orphaned batches /
        // missed finalize (safety net over the `images` queue worker), and
        // daily retention prune of generated files (audit rows are kept).
        // ⚠️ The overlap mutex is given a 5-minute expiry (default is 24 HOURS):
        // a container restart mid-run leaves the lock behind, and with the
        // default these safety nets would stop running for a day — long enough
        // for an in-flight batch to sit parked with nobody re-driving it. Both
        // sweeps finish in well under a minute and are idempotent (rows are
        // claimed atomically), so a short expiry cannot cause harmful overlap.
        // Liveness is visible at GET /api/v1/platform/status → beats.
        $schedule->command('images:sweep-batches')->everyMinute()->withoutOverlapping(5);
        $schedule->command('images:prune-batches')->dailyAt('04:30')->withoutOverlapping();
        // Safety net for bulk AI content runs (re-drive stalled batches/rows).
        $schedule->command('content:sweep-batches')->everyMinute()->withoutOverlapping(5);

        // Probe enabled third-party integrations so an expired key surfaces here rather than in a
        // customer's failed checkout. Hourly, not per-minute: several probes are billed per call
        // (Porter's get_quote among them), and a credential does not expire on a one-minute
        // boundary. Also prunes integration_logs on the same pass.
        $schedule->command('integrations:health')->hourly()->withoutOverlapping(30);

        // v2 Phase 12 observability: keyed heartbeat proving this cron loop is
        // alive — GET /api/v1/platform/status reports its staleness. DB-backed
        // (cache is not cross-process on every environment); guarded so a
        // pre-migration deploy window cannot error the scheduler.
        $schedule->call(function () {
            try {
                \Illuminate\Support\Facades\DB::table('platform_heartbeats')->updateOrInsert(
                    ['name' => 'scheduler'],
                    ['beat_at' => now()],
                );
            } catch (\Throwable $e) {
                // platform_heartbeats not migrated yet — skip silently
            }
        })->name('platform-heartbeat')->everyMinute();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
