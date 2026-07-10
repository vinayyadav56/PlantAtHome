<?php

use App\Modules\Cms\Http\Controllers\CmsController;
use Illuminate\Support\Facades\Route;

Route::prefix('cms')->group(function () {
    // Public reads
    Route::get('pages/{slug}', [CmsController::class, 'page']);
    Route::get('banners', [CmsController::class, 'banners']);

    // Admin writes
    Route::middleware(['v1.auth', 'v1.can:cms.manage'])->group(function () {
        Route::post('pages', [CmsController::class, 'storePage']);
        Route::post('banners', [CmsController::class, 'storeBanner']);
    });
});
