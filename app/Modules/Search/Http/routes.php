<?php

use App\Modules\Search\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Search routes (mounted under /api/v1) — public.
|--------------------------------------------------------------------------
*/

Route::get('search', [SearchController::class, 'search'])->middleware('throttle:120,1');
Route::get('search/autocomplete', [SearchController::class, 'autocomplete'])->middleware('throttle:240,1');
