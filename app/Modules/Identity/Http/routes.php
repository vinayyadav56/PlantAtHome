<?php

use App\Modules\Identity\Http\Controllers\AdminController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\NurseryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Identity module routes (mounted under /api/v1 by IdentityServiceProvider)
|--------------------------------------------------------------------------
*/

// Public authentication
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('refresh', [AuthController::class, 'refresh']);

    // Authenticated session actions
    Route::middleware('v1.auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// Admin-only surface (role gate) — proves role enforcement.
Route::prefix('admin')
    ->middleware(['v1.auth', 'v1.role:admin,super_admin'])
    ->group(function () {
        Route::get('ping', [AdminController::class, 'ping']);
    });

// Nursery-scoped surface (vendor isolation) — proves data isolation.
Route::prefix('nursery')
    ->middleware('v1.auth')
    ->group(function () {
        Route::get('{nursery}/dashboard', [NurseryController::class, 'dashboard'])
            ->middleware([
                'v1.role:nursery_owner,nursery_staff,admin,super_admin',
                'v1.nursery',
            ]);
    });
