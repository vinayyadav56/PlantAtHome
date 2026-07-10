<?php

use App\Modules\Pricing\Http\Controllers\PricingAdminController;
use App\Modules\Pricing\Http\Controllers\QuoteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Pricing routes (mounted under /api/v1 by the provider)
|--------------------------------------------------------------------------
| Quote is public (recomputed server-side). Base prices + tax rules are admin;
| vendor overrides are vendor-own or admin.
*/

Route::prefix('pricing')->group(function () {
    // Server-side quote (tamper-proof recompute)
    Route::middleware('v1.auth.optional')->post('quote', [QuoteController::class, 'quote']);

    // Admin pricing inputs
    Route::middleware(['v1.auth', 'v1.can:pricing.manage'])->group(function () {
        Route::post('base-prices', [PricingAdminController::class, 'setBasePrice']);
        Route::post('tax-rules', [PricingAdminController::class, 'setTaxRule']);
    });

    // Vendor overrides — own nursery or admin
    Route::middleware(['v1.auth', 'v1.can:nursery.manage_own,pricing.manage'])
        ->post('vendor-overrides', [PricingAdminController::class, 'setVendorOverride']);
});
