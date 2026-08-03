<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\PlatformStatusReport;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;

/**
 * v2 Phase 12 (observability) — GET /api/v1/platform/status (admin-gated).
 * One call answers "is the async machinery alive?". The computation lives in
 * PlatformStatusReport so the admin command-center aggregator can consume it
 * server-side without a second authenticated HTTP hop; this controller is the
 * v1 HTTP face of the same report.
 */
final class StatusController extends ApiController
{
    public function show(): JsonResponse
    {
        return $this->ok((new PlatformStatusReport())->report());
    }
}
