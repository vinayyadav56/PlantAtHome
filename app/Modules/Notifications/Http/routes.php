<?php

use App\Modules\Notifications\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('notifications')
    ->middleware(['v1.auth', 'v1.can:orders.view_all'])
    ->group(function () {
        Route::get('log', [NotificationController::class, 'log']);
        Route::post('templates', [NotificationController::class, 'storeTemplate']);
    });
