<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Controllers;

use App\Modules\Marketing\Application\AnalyticsService;
use App\Modules\Marketing\Application\DashboardService;
use App\Modules\Marketing\Http\Concerns\ResolvesMarketingModels;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;

final class AnalyticsController extends ApiController
{
    use ResolvesMarketingModels;

    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly DashboardService $dashboard,
    ) {
    }

    /** GET marketing/dashboard — module overview. */
    public function dashboard(): JsonResponse
    {
        return $this->ok($this->dashboard->overview());
    }

    /** GET marketing/campaigns/{campaign}/analytics — funnel + rates + timeseries. */
    public function campaign(string $campaign): JsonResponse
    {
        return $this->ok($this->analytics->forCampaign($this->campaignOrFail($campaign)));
    }
}
