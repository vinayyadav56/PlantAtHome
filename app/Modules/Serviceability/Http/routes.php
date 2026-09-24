<?php

use App\Modules\Serviceability\Http\Controllers\CityController;
use App\Modules\Serviceability\Http\Controllers\CoverageController;
use App\Modules\Serviceability\Http\Controllers\GeoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Serviceability routes (mounted under /api/v1 by the provider)
|--------------------------------------------------------------------------
| City lists + availability/delivery checks are public. City creation is admin;
| vendor coverage is vendor-own or admin.
|
| NOTE: this is the RADIUS model (svc_cities / svc_pincodes /
| svc_vendor_areas), which is NOT how the storefront answers "can this be
| delivered here?" — that is VendorServiceabilityResolver over the pincode
| rules (app/Modules/Serviceability/Application). No client calls these
| endpoints, and the plan was to unroute them as a second serviceability
| engine — but V2 search composes its `?city=` filter from this model, and
| these are the only routes that populate it. Retiring it means retiring that
| filter with it, deliberately, not unrouting half of it.
*/

Route::prefix('serviceability')->group(function () {
    // Public reads
    Route::get('cities', [CityController::class, 'index']);
    Route::get('cities/{city}/serviceable', [CityController::class, 'serviceable']);
    Route::get('availability', [CoverageController::class, 'availability']);
    Route::post('delivery-check', [CoverageController::class, 'deliveryCheck']);

    // Public geo master lookups (address forms + coverage pickers)
    Route::prefix('geo')->middleware('v1.auth.optional')->group(function () {
        Route::get('states', [GeoController::class, 'states']);
        Route::get('districts', [GeoController::class, 'districts']);
        Route::get('cities', [GeoController::class, 'cities']);
        Route::get('postal-codes', [GeoController::class, 'postalCodes']);
    });

    // Admin: cities
    Route::post('cities', [CityController::class, 'store'])
        ->middleware(['v1.auth', 'v1.can:serviceability.manage']);

    // Vendor coverage — own nursery or admin
    Route::put('coverage', [CoverageController::class, 'setCoverage'])
        ->middleware(['v1.auth', 'v1.can:nursery.manage_own,serviceability.manage']);
});
