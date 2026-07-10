<?php

use App\Modules\Search\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Search routes (mounted under /api/v1) — public.
|--------------------------------------------------------------------------
*/

Route::get('search', [SearchController::class, 'search']);
Route::get('search/autocomplete', [SearchController::class, 'autocomplete']);
