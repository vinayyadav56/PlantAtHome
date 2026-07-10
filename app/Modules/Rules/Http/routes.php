<?php

use App\Modules\Rules\Http\Controllers\RuleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rules Engine routes (mounted under /api/v1 by the provider)
|--------------------------------------------------------------------------
| Admin-only (rules.manage). A rule is data — CRUD here changes behaviour with
| zero deploys.
*/

Route::prefix('rules')
    ->middleware(['v1.auth', 'v1.can:rules.manage'])
    ->group(function () {
        Route::get('/', [RuleController::class, 'index']);
        Route::post('/', [RuleController::class, 'store']);
        Route::get('{rule}', [RuleController::class, 'show']);
        Route::patch('{rule}', [RuleController::class, 'update']);
        Route::delete('{rule}', [RuleController::class, 'destroy']);
    });
