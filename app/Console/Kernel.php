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

        // Vendor KYC clock: warn vendors whose document deadline is near, put
        // overdue vendors on hold. 04:15 to stay clear of the 03:30/03:45/04:00/
        // 04:30 slots. The 30-min mutex expiry matters: bare withoutOverlapping()
        // holds its lock for 24 HOURS if a container dies mid-run.
        $schedule->command('marvel:sweep-kyc-deadlines')->dailyAt('04:15')->withoutOverlapping(30);

        // Settle vendor earnings past their T+N hold into per-vendor settlements.
        // One retention story for every log/audit table (request_logs days come from
        // the admin setting). Slotted between the 03:30 and 04:00 heavy jobs.
        $schedule->command('logs:prune')->dailyAt('03:45')->withoutOverlapping(30);
        // Geo enrichment for the logs drawer: batch-resolves DISTINCT recent IPs;
        // request path never does lookups. 20-min scan > 10-min cadence = overlap
        // margin so an IP seen during a slow sweep is not missed.
        $schedule->command('logs:enrich-ips')->everyTenMinutes()->withoutOverlapping(5);

        $schedule->command('marvel:run-settlements')->dailyAt('04:00')->withoutOverlapping();

        // Courier fulfilment safety net: alarm on shipments that auto-booking never dispatched
        // (payable, non-cancelled orders left unbooked past the SLA). Alarm only — it never
        // rebooks. No-op when courier is off. Status recovery for BOOKED shipments lives in the
        // Go shipping-service's reconcile loop, not here. 5-min mutex so a dead container can't
        // hold the lock for 24h.
        $schedule->command('courier:sweep-undispatched')->everyFifteenMinutes()->withoutOverlapping(5);

        // The half the sweep above cannot see: it watches unbooked SHIPMENTS, and a shipment
        // only exists once an order has a vendor. With auto-assignment opt-in, an unassigned
        // order had no watchdog at all — no vendor, no shipment, and invisible to the vendor
        // dashboards, so it just sat. Alarms only; assigning stays the operator's call.
        $schedule->command('orders:sweep-unassigned')->everyFifteenMinutes()->withoutOverlapping(5);

        // Courier STATUS safety net (the other half of the sweep above, which only alarms on legs
        // that were never booked). Push delivery is partner webhook → Go outbox → our
        // /api/shipping/callback; every hop can drop an event, and a partner that was never given
        // our webhook URL pushes nothing at all. This polls booked, non-terminal legs and replays
        // live status through the same applyNormalizedStatus seam, so it is idempotent.
        // Every row costs a live partner Track call (Porter rate-limits ~1/min per order), so this
        // is deliberately slow + bounded, NOT everyMinute. 10-min mutex expiry, never the 24h default.
        $schedule->command('courier:reconcile-shipments')->everyThirtyMinutes()->withoutOverlapping(10);

        // Courier margin watch: orders whose delivery fee was less than the partner cost
        // (charged delivery_fee vs Σ shipment booked_cost). Report-only (logs); dormant
        // until courier bookings exist.
        $schedule->command('courier:margin-report')->dailyAt('05:00')->withoutOverlapping();

        // v2 Inventory (Phase 5): return stock held by abandoned checkouts whose
        // reservation TTL has lapsed. No-op when nothing is expired.
        $schedule->command('inventory:release-expired')->everyMinute()->withoutOverlapping();

        // Legacy (marvel) order path: cancel prepaid orders left unpaid past the
        // threshold so their deducted stock + consumed coupon slots return
        // (creation deducts inside the txn; abandonment/webhook-failed otherwise
        // leaks them forever). COD/wallet exempt; restore is idempotent.
        $schedule->command('orders:cancel-stale-unpaid')->everyFifteenMinutes()->withoutOverlapping();

        // v2 outbox relay (Phase 0): drain pending domain events to subscribers.
        $schedule->command('outbox:relay --once')->everyMinute()->withoutOverlapping();

        // v2 Marketing: launch campaigns whose scheduled time has arrived. Only
        // creates runs + enqueues jobs (never sends inline), so it's fast.
        $schedule->command('marketing:dispatch-due')->everyMinute()->withoutOverlapping();

        // Partner-order ledger: mirror partner webhooks from the shipping service and keep
        // /partner-orders' lifecycle state live. Every minute because Porter's UAT simulator
        // advances a flow every ~60s and its Track API allows one call per order per minute —
        // any slower and the intermediate states are unobservable, which is exactly the bug
        // this exists to fix. Bounded per pass; 5-minute mutex expiry per the house rule.
        $schedule->command('console-orders:reconcile')->everyMinute()->withoutOverlapping(5);

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
