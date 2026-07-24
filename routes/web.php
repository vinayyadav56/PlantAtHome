<?php

use Illuminate\Support\Facades\Route;
use Marvel\Http\Controllers\LocationCapturePageController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Location Capture Email System — public capture page + submit + open pixel.
// The emailed one-time token IS the authorization; sessions/CSRF come from the
// web group; throttles keep token guessing and submit spam impractical.
Route::get('/location/open/{uuid}.gif', [LocationCapturePageController::class, 'openPixel'])
    ->where('uuid', '[0-9a-fA-F-]{36}')
    ->middleware('throttle:60,1');
Route::get('/location/{token}', [LocationCapturePageController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{24,128}')
    ->middleware('throttle:30,1');
Route::post('/location/submit', [LocationCapturePageController::class, 'submit'])
    ->middleware('throttle:15,1');
