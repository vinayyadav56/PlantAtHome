<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Services\MetricsService;

/**
 * Operations Command Center (Phase 1) — super-admin only. Thin: every number
 * comes from MetricsService (the single metrics source). The frontend polls
 * these endpoints (react-query refetchInterval) for a near-real-time feel.
 */
class CommandCenterController extends CoreController
{
    public function __construct(protected MetricsService $metrics)
    {
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
}
