<?php

declare(strict_types=1);

use App\Modules\Marketing\Http\Controllers\AnalyticsController;
use App\Modules\Marketing\Http\Controllers\AudienceController;
use App\Modules\Marketing\Http\Controllers\CampaignController;
use App\Modules\Marketing\Http\Controllers\DeliveryLogController;
use App\Modules\Marketing\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

/*
 * Marketing Automation — all admin-only (marketing.manage). Mounted under
 * /api/v1 by MarketingServiceProvider. Nothing here sends: send/retry only
 * create runs and enqueue jobs. More-specific paths precede {param} routes.
 */
Route::middleware(['v1.auth', 'v1.can:marketing.manage'])
    ->prefix('marketing')
    ->group(function () {
        // Dashboard
        Route::get('dashboard', [AnalyticsController::class, 'dashboard']);

        // Audience Builder
        Route::get('audiences', [AudienceController::class, 'index']);
        Route::post('audiences/preview', [AudienceController::class, 'preview']);
        Route::post('audiences', [AudienceController::class, 'store']);
        Route::get('audiences/{audience}/versions/{version}', [AudienceController::class, 'version']);
        Route::post('audiences/{audience}/refresh', [AudienceController::class, 'refresh']);
        Route::get('audiences/{audience}', [AudienceController::class, 'show']);
        Route::match(['put', 'patch'], 'audiences/{audience}', [AudienceController::class, 'update']);
        Route::delete('audiences/{audience}', [AudienceController::class, 'destroy']);

        // Templates
        Route::get('templates', [TemplateController::class, 'index']);
        Route::post('templates', [TemplateController::class, 'store']);
        Route::get('templates/{template}', [TemplateController::class, 'show']);
        Route::match(['put', 'patch'], 'templates/{template}', [TemplateController::class, 'update']);
        Route::delete('templates/{template}', [TemplateController::class, 'destroy']);

        // Campaigns
        Route::get('campaigns', [CampaignController::class, 'index']);
        Route::post('campaigns', [CampaignController::class, 'store']);
        Route::post('campaigns/{campaign}/send', [CampaignController::class, 'send']);
        Route::post('campaigns/{campaign}/retry', [CampaignController::class, 'retry']);
        Route::post('campaigns/{campaign}/pause', [CampaignController::class, 'pause']);
        Route::post('campaigns/{campaign}/resume', [CampaignController::class, 'resume']);
        Route::get('campaigns/{campaign}/runs', [CampaignController::class, 'runs']);
        Route::get('campaigns/{campaign}/analytics', [AnalyticsController::class, 'campaign']);
        Route::get('campaigns/{campaign}', [CampaignController::class, 'show']);
        Route::match(['put', 'patch'], 'campaigns/{campaign}', [CampaignController::class, 'update']);
        Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy']);

        // Delivery Tracking / Retry Center feed
        Route::get('notifications', [DeliveryLogController::class, 'index']);
        Route::get('notifications/{notification}', [DeliveryLogController::class, 'show']);
        Route::post('notifications/{notification}/status', [DeliveryLogController::class, 'status']);
    });
