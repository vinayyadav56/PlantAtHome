<?php

use App\Modules\Platform\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 routes (modular monolith)
|--------------------------------------------------------------------------
| Served under the /api/v1 prefix by SharedKernelServiceProvider. New modules
| register their routes here (or via their own module route files included from
| here). The legacy Pickbazar routes remain under /api (unversioned).
*/

Route::get('health', [HealthController::class, 'show']);

Route::prefix('platform')->group(function () {
    // Demo endpoint that emits a domain event through the transactional outbox.
    Route::post('ping', [HealthController::class, 'ping']);
});
