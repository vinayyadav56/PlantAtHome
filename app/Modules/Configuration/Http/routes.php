<?php

use App\Modules\Configuration\Http\Controllers\ConfigAdminController;
use App\Modules\Configuration\Http\Controllers\ConfigResolutionController;
use App\Modules\Configuration\Http\Controllers\NurseryEnablementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Configuration Engine routes (mounted under /api/v1 by the provider)
|--------------------------------------------------------------------------
| Resolution is customer-facing (optional auth). Group/option/assignment/
| compatibility management is admin (config.manage). Enablement is nursery-
| scoped self-service.
*/

Route::prefix('config')->group(function () {
    // ── Resolution (customer-facing) ──────────────────────────────────────
    Route::middleware('v1.auth.optional')->group(function () {
        Route::get('products/{product}/configuration', [ConfigResolutionController::class, 'resolve']);
        Route::post('products/{product}/validate-selection', [ConfigResolutionController::class, 'validate']);
    });

    // ── Admin management (config.manage) ──────────────────────────────────
    Route::middleware(['v1.auth', 'v1.can:config.manage'])->group(function () {
        Route::get('groups', [ConfigAdminController::class, 'indexGroups']);
        Route::post('groups', [ConfigAdminController::class, 'storeGroup']);
        Route::post('groups/{group}/options', [ConfigAdminController::class, 'storeOption']);
        Route::post('products/{product}/assignments', [ConfigAdminController::class, 'assign']);
        Route::post('options/{option}/compatibility', [ConfigAdminController::class, 'setCompatibility']);
    });

    // ── Nursery enablement (own nursery only; admins any) ─────────────────
    Route::middleware(['v1.auth', 'v1.can:nursery.manage_own,config.manage', 'v1.nursery'])
        ->put('nursery/{nursery}/options/{option}/enablement', [NurseryEnablementController::class, 'set']);
});
