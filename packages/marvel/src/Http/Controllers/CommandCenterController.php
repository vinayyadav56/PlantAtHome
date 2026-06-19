<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
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
    ) {
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
}
