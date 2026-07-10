<?php

use App\Modules\Promotions\Http\Controllers\PromotionController;
use Illuminate\Support\Facades\Route;

Route::prefix('promotions')->group(function () {
    Route::post('validate', [PromotionController::class, 'validateCoupon'])->middleware('v1.auth.optional');

    Route::middleware(['v1.auth', 'v1.can:promotions.manage'])->group(function () {
        Route::get('coupons', [PromotionController::class, 'index']);
        Route::post('coupons', [PromotionController::class, 'store']);
    });
});
