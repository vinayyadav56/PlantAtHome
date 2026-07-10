<?php

use App\Modules\Catalog\Http\Controllers\AttributeController;
use App\Modules\Catalog\Http\Controllers\CategoryController;
use App\Modules\Catalog\Http\Controllers\ProductController;
use App\Modules\Catalog\Http\Controllers\ProposalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Master Catalog routes (mounted under /api/v1 by CatalogServiceProvider)
|--------------------------------------------------------------------------
| Reads are public (city-aware filtering lands in a later phase). Writes are
| admin-only (catalog.manage). Nurseries never create products — they file
| proposals (catalog.propose).
*/

Route::prefix('catalog')->group(function () {
    // ── Public reads ──────────────────────────────────────────────────────
    // Optional auth: guests see published only; an admin (catalog.manage) may
    // additionally see drafts/archived. Never rejects a guest.
    Route::middleware('v1.auth.optional')->group(function () {
        Route::get('categories', [CategoryController::class, 'index']);
        Route::get('categories/{category}', [CategoryController::class, 'show']);
        Route::get('attributes', [AttributeController::class, 'index']);
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/{product}', [ProductController::class, 'show']);
        Route::get('products/{product}/variants', [ProductController::class, 'variants']);
    });

    // ── Admin writes (catalog.manage) ─────────────────────────────────────
    Route::middleware(['v1.auth', 'v1.can:catalog.manage'])->group(function () {
        Route::post('categories', [CategoryController::class, 'store']);
        Route::patch('categories/{category}', [CategoryController::class, 'update']);

        Route::post('attributes', [AttributeController::class, 'store']);

        Route::post('products', [ProductController::class, 'store']);
        Route::patch('products/{product}', [ProductController::class, 'update']);
        Route::post('products/{product}/publish', [ProductController::class, 'publish']);
        Route::post('products/{product}/variants', [ProductController::class, 'storeVariant']);

        Route::post('proposals/{proposal}/approve', [ProposalController::class, 'approve']);
        Route::post('proposals/{proposal}/reject', [ProposalController::class, 'reject']);
    });

    // ── Proposals: a nursery files + lists its own; admins list all ───────
    Route::middleware('v1.auth')->group(function () {
        Route::post('proposals', [ProposalController::class, 'store'])
            ->middleware('v1.can:catalog.propose');
        Route::get('proposals', [ProposalController::class, 'index'])
            ->middleware('v1.can:catalog.propose,catalog.manage');
        Route::get('proposals/{proposal}', [ProposalController::class, 'show'])
            ->middleware('v1.can:catalog.propose,catalog.manage');
    });
});
