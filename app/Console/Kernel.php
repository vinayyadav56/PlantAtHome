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
        $schedule->command('marvel:run-settlements')->dailyAt('04:00')->withoutOverlapping();

        // Re-track open courier shipments + re-apply status (recovers missed/failed webhooks).
        // No-op unless courier is enabled, so it's safe to always schedule.
        $schedule->command('marvel:courier-reconcile')->hourly()->withoutOverlapping();

        // v2 Inventory (Phase 5): return stock held by abandoned checkouts whose
        // reservation TTL has lapsed. No-op when nothing is expired.
        $schedule->command('inventory:release-expired')->everyMinute()->withoutOverlapping();

        // v2 outbox relay (Phase 0): drain pending domain events to subscribers.
        $schedule->command('outbox:relay --once')->everyMinute()->withoutOverlapping();

        // v2 Marketing: launch campaigns whose scheduled time has arrived. Only
        // creates runs + enqueues jobs (never sends inline), so it's fast.
        $schedule->command('marketing:dispatch-due')->everyMinute()->withoutOverlapping();

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
