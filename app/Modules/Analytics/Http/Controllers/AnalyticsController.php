<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Application\AnalyticsService;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;

final class AnalyticsController extends ApiController
{
    public function __construct(private readonly AnalyticsService $analytics)
    {
    }

    /** GET /api/v1/analytics/kpis (admin) */
    public function kpis(): JsonResponse
    {
        return $this->ok($this->analytics->kpis());
    }
}
