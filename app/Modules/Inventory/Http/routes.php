<?php

use App\Modules\Inventory\Http\Controllers\InventoryController;
use App\Modules\Inventory\Http\Controllers\ReservationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory routes (mounted under /api/v1 by the provider)
|--------------------------------------------------------------------------
| Availability is public. Stock management is vendor-own (nursery.manage_own)
| or admin. Reservation lifecycle is system/admin (inventory.manage) — checkout
| drives it in Phase 8.
*/

Route::prefix('inventory')->group(function () {
    // Public availability
    Route::get('availability', [InventoryController::class, 'availability']);

    // Stock management — vendor's own or admin
    Route::put('stock', [InventoryController::class, 'setStock'])
        ->middleware(['v1.auth', 'v1.can:nursery.manage_own,inventory.manage']);

    // Reservation lifecycle
    Route::middleware(['v1.auth', 'v1.can:inventory.manage'])->group(function () {
        Route::post('reservations', [ReservationController::class, 'reserve']);
        Route::post('reservations/{session}/commit', [ReservationController::class, 'commit']);
        Route::post('reservations/{session}/release', [ReservationController::class, 'release']);
    });
});
