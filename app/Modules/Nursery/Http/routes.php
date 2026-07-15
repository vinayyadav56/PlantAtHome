<?php

use App\Modules\Nursery\Http\Controllers\NurseryAdminController;
use App\Modules\Nursery\Http\Controllers\WithdrawalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Nursery routes (mounted under /api/v1 by NurseryServiceProvider)
|--------------------------------------------------------------------------
| Vendor lifecycle is admin-only (nurseries.manage). Payout decisions are
| admin-only (withdrawals.manage). A nursery owner may file withdrawal
| requests and read the balance for their OWN nursery only.
*/

// ── Admin: vendor lifecycle ───────────────────────────────────────────────
Route::middleware(['v1.auth', 'v1.can:nurseries.manage'])->group(function () {
    Route::get('nurseries', [NurseryAdminController::class, 'index']);
    Route::post('nurseries', [NurseryAdminController::class, 'store']);
    Route::get('nurseries/{nursery}', [NurseryAdminController::class, 'show']);
    Route::patch('nurseries/{nursery}', [NurseryAdminController::class, 'update']);
    Route::post('nurseries/{nursery}/approve', [NurseryAdminController::class, 'approve']);
    Route::post('nurseries/{nursery}/suspend', [NurseryAdminController::class, 'suspend']);
    Route::patch('nurseries/{nursery}/commission', [NurseryAdminController::class, 'commission']);
    Route::post('nurseries/{nursery}/documents/{document}/status', [NurseryAdminController::class, 'documentStatus']);
    Route::post('nurseries/{nursery}/transfer-ownership', [NurseryAdminController::class, 'transferOwnership']);
});

// ── Admin: payout decisions ───────────────────────────────────────────────
Route::middleware(['v1.auth', 'v1.can:withdrawals.manage'])->group(function () {
    Route::get('withdrawals', [WithdrawalController::class, 'index']);
    Route::get('withdrawals/{withdrawal}', [WithdrawalController::class, 'show']);
    Route::post('withdrawals/{withdrawal}/decide', [WithdrawalController::class, 'decide']);
});

// ── Owner-or-admin: file a request / read the balance (own nursery only) ─
Route::middleware(['v1.auth', 'v1.role:nursery_owner,admin,super_admin'])->group(function () {
    Route::post('nurseries/{nursery}/withdrawals', [WithdrawalController::class, 'request']);
    Route::get('nurseries/{nursery}/balance', [WithdrawalController::class, 'balance']);
});
