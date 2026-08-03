<?php

namespace Marvel\Http\Controllers;

use App\Modules\Platform\Application\PlatformStatusReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Marvel\Services\ActivityStreamService;
use Marvel\Services\CustomerMetricsService;
use Marvel\Services\InventoryMetricsService;
use Marvel\Services\MetricsService;
use Marvel\Services\VisitorMetricsService;

/**
 * Operations Command Center (Phase 1) — super-admin only. Thin: every number
 * comes from MetricsService (the single metrics source). The frontend polls
 * these endpoints (react-query refetchInterval) for a near-real-time feel.
 * Phase 3 adds the visitor / live-activity NOC reads (VisitorMetricsService).
 */
class CommandCenterController extends CoreController
{
    public function __construct(
        protected MetricsService $metrics,
        protected VisitorMetricsService $visitors,
        protected InventoryMetricsService $inventory,
        protected CustomerMetricsService $customers,
        protected ActivityStreamService $activity,
    ) {
    }

    // ── Live Activity NOC — merged event stream + pulse metrics ─────────────

    /** Delta-pollable event stream: ?since=<server_time from the last poll>. */
    public function eventStream(Request $request)
    {
        return $this->activity->events(
            $request->get('since') ?: null,
            min(100, max(10, (int) ($request->get('limit') ?: 80)))
        );
    }

    /** Per-minute sparkline series + computed insights (cached 12s). */
    public function pulse(Request $request)
    {
        return $this->activity->pulse();
    }

    public function overview(Request $request)
    {
        return $this->metrics->overview();
    }

    public function liveOperations(Request $request)
    {
        return $this->metrics->liveOperations();
    }

    public function cityHealth(Request $request)
    {
        return $this->metrics->cityHealth((int) ($request->limit ?? 24));
    }

    public function deliveryOps(Request $request)
    {
        return $this->metrics->deliveryOps();
    }

    public function courierPositions(Request $request)
    {
        return ['couriers' => $this->metrics->courierPositions()];
    }

    /** Per-city dashboard (City Command Center). City passed by name (?city=). */
    public function cityDashboard(Request $request)
    {
        $city = trim((string) $request->get('city', ''));
        if ($city === '') {
            return ['city' => '', 'orders_30d' => 0, 'revenue_30d' => 0, 'customers' => 0, 'vendors' => 0, 'by_status' => [], 'revenue_trend' => [], 'recent_orders' => []];
        }
        return $this->metrics->cityDashboard($city);
    }

    // ── Visitor / Live Activity NOC (Phase 3) ───────────────────────────────
    public function liveVisitors(Request $request)
    {
        return $this->visitors->liveVisitors();
    }

    public function visitorJourney(Request $request)
    {
        $visitorId = trim((string) $request->get('visitor_id', ''));
        if ($visitorId === '') {
            return ['visitor' => null, 'events' => []];
        }
        return $this->visitors->visitorJourney($visitorId);
    }

    public function funnel(Request $request)
    {
        return $this->visitors->funnel((int) ($request->get('days', 1)));
    }

    public function activityFeed(Request $request)
    {
        return ['items' => $this->visitors->activityFeed((int) ($request->get('limit', 40)))];
    }

    // ── Phase 4 — Inventory + Customer Intelligence ─────────────────────────
    public function inventory(Request $request)
    {
        return $this->inventory->overview();
    }

    public function customerIntelligence(Request $request)
    {
        return $this->customers->overview();
    }

    // ── Mission Control — platform health + computed incidents ──────────────

    /**
     * One aggregated answer to "is the platform healthy?", assembled from the
     * three health feeds that existed but had no UI consumer: the v1 platform
     * status report (outbox/queue/scheduler), the Go shipping-service /health,
     * and integration_providers.health_status (hourly sweep).
     *
     * Incidents are PURE COMPUTATION over those signals — no alert store, no
     * new subsystem. Stable string ids so the UI can key rows; each carries a
     * deep link to the page where the fix lives. Cached 10s so N open admin
     * tabs share one computation (the shipping /health probe included).
     */
    public function platformHealth(Request $request)
    {
        return Cache::remember('cc_platform_health', 10, function () {
            $incidents = [];
            $add = function (string $id, string $severity, string $title, string $detail, string $href) use (&$incidents) {
                $incidents[] = compact('id', 'severity', 'title', 'detail', 'href');
            };

            // 1. Async machinery (server-side reuse — no second HTTP hop).
            $platform = [];
            try {
                $platform = (new PlatformStatusReport())->report();
            } catch (\Throwable $e) {
                $add('platform_report_failed', 'critical', 'Platform status unavailable', $e->getMessage(), '/setup/logs');
            }
            if ($platform !== []) {
                if (empty($platform['scheduler']['alive'])) {
                    $age = $platform['scheduler']['beat_age_seconds'];
                    $add('scheduler_stale', 'critical', 'Scheduler heartbeat stale',
                        $age === null ? 'No heartbeat ever recorded — is cron running?' : "Last beat {$age}s ago (threshold 180s). Cron loop is likely dead.",
                        '/setup/logs');
                }
                if (empty($platform['outbox']['flowing'])) {
                    $add('outbox_stalled', 'critical', 'Outbox relay stalled',
                        "Oldest pending event is {$platform['outbox']['oldest_pending_age_seconds']}s old (threshold 300s).",
                        '/setup/logs');
                }
                if (($platform['outbox']['failed'] ?? 0) > 0) {
                    $add('outbox_failed', 'warning', 'Failed outbox events',
                        "{$platform['outbox']['failed']} event(s) in FAILED state need attention.",
                        '/setup/logs');
                }
                if (($platform['queue']['depth'] ?? 0) > 500) {
                    $add('queue_backlog', 'warning', 'Queue backlog',
                        "{$platform['queue']['depth']} jobs waiting — workers may be down or saturated.",
                        '/setup/logs');
                }
                if (($platform['queue']['failed_jobs_24h'] ?? 0) > 0) {
                    $add('failed_jobs', 'warning', 'Failed queue jobs',
                        "{$platform['queue']['failed_jobs_24h']} job(s) failed in the last 24h.",
                        '/setup/logs');
                }
            }

            // 2. Go shipping service — 2s cap so a dead service cannot stall the payload.
            $shipping = ['status' => 'unreachable', 'checks' => null];
            $shippingUrl = rtrim((string) config('services.shipping_service.url'), '/');
            if ($shippingUrl !== '') {
                try {
                    $res = Http::timeout(2)->get($shippingUrl . '/health');
                    $body = (array) $res->json();
                    $shipping = ['status' => (string) ($body['status'] ?? 'unknown'), 'checks' => $body['checks'] ?? null];
                } catch (\Throwable) {
                    // stays unreachable
                }
                if ($shipping['status'] === 'degraded') {
                    $add('shipping_degraded', 'warning', 'Shipping service degraded',
                        'The courier microservice reports a failing dependency (db/redis).',
                        '/settings/shipping-integrations');
                } elseif ($shipping['status'] === 'unreachable') {
                    $add('shipping_unreachable', 'critical', 'Shipping service unreachable',
                        'No response from the courier microservice — quotes and bookings will fail.',
                        '/settings/shipping-integrations');
                }
            } else {
                $shipping = ['status' => 'not_configured', 'checks' => null];
            }

            // 3. Integration health (maintained by the hourly integrations:health sweep).
            $integrations = [];
            try {
                $integrations = DB::table('integration_providers')
                    ->where('enabled', true)
                    ->get(['provider_slug', 'health_status', 'health_checked_at'])
                    ->map(fn ($r) => [
                        'slug' => $r->provider_slug,
                        'health_status' => $r->health_status,
                        'checked_at' => $r->health_checked_at,
                    ])->values()->all();
            } catch (\Throwable) {
            }
            foreach ($integrations as $i) {
                if (!in_array($i['health_status'], ['connected', 'unknown'], true)) {
                    $add('integration_' . $i['slug'], 'warning', "Integration unhealthy: {$i['slug']}",
                        "health check reports '{$i['health_status']}'.",
                        '/settings/integrations');
                }
            }

            // 4. Kill switches — deliberate operator states: surfaced, not alarmed.
            $flags = [];
            try {
                $flags = app(\Marvel\Services\ServiceAvailabilityService::class)->platformFlags();
            } catch (\Throwable) {
            }
            foreach (['stop_platform', 'stop_orders', 'stop_deliveries', 'maintenance'] as $flag) {
                if (!empty($flags[$flag])) {
                    $add('switch_' . $flag, 'info', 'Kill switch engaged: ' . str_replace('_', ' ', $flag),
                        'Engaged deliberately from Service Control — disable it there when the situation clears.',
                        '/operations/service-control');
                }
            }

            // 5. Recent exceptions (graceful before the L1 migration lands).
            $exceptions15m = 0;
            if (Schema::hasTable('request_log_exceptions')) {
                $exceptions15m = (int) DB::table('request_log_exceptions')
                    ->where('created_at', '>=', now()->subMinutes(15))->count();
                if ($exceptions15m > 10) {
                    $add('exception_burst', 'warning', 'Exception burst',
                        "{$exceptions15m} exceptions captured in the last 15 minutes.",
                        '/setup/logs');
                }
            }

            $severities = array_column($incidents, 'severity');
            $overall = in_array('critical', $severities, true) ? 'critical'
                : (in_array('warning', $severities, true) ? 'warning' : 'ok');

            return [
                'overall' => $overall,
                'platform' => $platform,
                'shipping' => $shipping,
                'integrations' => $integrations,
                'kill_switches' => [
                    'stop_platform' => (bool) ($flags['stop_platform'] ?? false),
                    'stop_orders' => (bool) ($flags['stop_orders'] ?? false),
                    'stop_deliveries' => (bool) ($flags['stop_deliveries'] ?? false),
                    'maintenance' => (bool) ($flags['maintenance'] ?? false),
                ],
                'exceptions_15m' => $exceptions15m,
                'incidents' => $incidents,
                'generated_at' => now()->toIso8601String(),
            ];
        });
    }
}
