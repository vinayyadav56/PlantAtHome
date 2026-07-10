<?php

use App\Modules\Serviceability\Http\Controllers\CityController;
use App\Modules\Serviceability\Http\Controllers\CoverageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Serviceability routes (mounted under /api/v1 by the provider)
|--------------------------------------------------------------------------
| City lists + availability/delivery checks are public. City creation is admin;
| vendor coverage is vendor-own or admin.
*/

Route::prefix('serviceability')->group(function () {
    // Public reads
    Route::get('cities', [CityController::class, 'index']);
    Route::get('cities/{city}/serviceable', [CityController::class, 'serviceable']);
    Route::get('availability', [CoverageController::class, 'availability']);
    Route::post('delivery-check', [CoverageController::class, 'deliveryCheck']);

    // Admin: cities
    Route::post('cities', [CityController::class, 'store'])
        ->middleware(['v1.auth', 'v1.can:serviceability.manage']);

    // Vendor coverage — own nursery or admin
    Route::put('coverage', [CoverageController::class, 'setCoverage'])
        ->middleware(['v1.auth', 'v1.can:nursery.manage_own,serviceability.manage']);
});
