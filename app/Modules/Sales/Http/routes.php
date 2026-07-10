<?php

use App\Modules\Sales\Http\Controllers\CartController;
use App\Modules\Sales\Http\Controllers\CheckoutController;
use App\Modules\Sales\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sales routes (cart / checkout / orders) — mounted under /api/v1
|--------------------------------------------------------------------------
| All authenticated. The customer owns cart/checkout/parent-order; a nursery
| only ever sees/acts on its own sub-orders.
*/

Route::middleware('v1.auth')->group(function () {
    // Cart
    Route::get('cart', [CartController::class, 'show']);
    Route::post('cart/items', [CartController::class, 'addItem']);
    Route::delete('cart/items/{item}', [CartController::class, 'removeItem']);

    // Checkout
    Route::post('checkout', [CheckoutController::class, 'start']);
    Route::post('checkout/{session}/pay', [CheckoutController::class, 'pay']);

    // Customer parent order
    Route::get('orders/{order}', [OrderController::class, 'show']);

    // Nursery fulfillment (own sub-orders only)
    Route::get('nursery/sub-orders', [OrderController::class, 'nurseryQueue'])
        ->middleware('v1.can:nursery.view_own,orders.view_all');
    Route::post('nursery/sub-orders/{sub}/transition', [OrderController::class, 'transition'])
        ->middleware('v1.can:nursery.manage_own,orders.view_all');
    Route::post('nursery/sub-orders/{sub}/refund', [OrderController::class, 'refund'])
        ->middleware('v1.can:nursery.manage_own,orders.view_all');
});
