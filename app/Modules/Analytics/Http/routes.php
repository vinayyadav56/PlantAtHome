<?php

use App\Modules\Analytics\Http\Controllers\AnalyticsController;
use Illuminate\Support\Facades\Route;

Route::prefix('analytics')
    ->middleware(['v1.auth', 'v1.can:analytics.view'])
    ->group(function () {
        Route::get('kpis', [AnalyticsController::class, 'kpis']);
    });
