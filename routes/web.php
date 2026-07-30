<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Marvel\Http\Controllers\LocationCapturePageController;

/*
 * EMERGENCY DB disk reclaim — a WEB route (no auth:sanctum) so it works when the
 * DB volume is full and every authenticated request 500s (Sanctum's last_used_at
 * write fails). DROP frees space on a full disk (TRUNCATE/DELETE deadlock when
 * full). Drops+recreates only expendable operational tables.
 *
 * OPT-IN ONLY: the route does not exist unless EMERGENCY_RECLAIM_SECRET is
 * explicitly set (>=16 chars). It previously fell back to a secret committed
 * in this file — i.e. public in git history — which left an unauthenticated
 * endpoint able to DROP `jobs` (destroying every queued order email and
 * shipping booking) and `sessions` (logging out every user).
 *
 * Set it on the small-volume Railway staging DB, where this is the only
 * recovery path once the disk is full. Do NOT set it in production: that host
 * has a real shell (SSM), so it never needs to make this trade. Note APP_ENV
 * is an unreliable discriminator here (staging also reports 'production'),
 * which is exactly why the gate is the secret's presence, not the env name.
 */
$reclaimSecret = (string) env('EMERGENCY_RECLAIM_SECRET', '');
if (strlen($reclaimSecret) >= 16) {
Route::get('/system/emergency-db-reclaim', function (\Illuminate\Http\Request $request) use ($reclaimSecret) {
    if (!hash_equals($reclaimSecret, (string) $request->query('secret'))) {
        abort(404);
    }
    $result = [];
    foreach ([
        'request_logs' => 'packages/marvel/database/migrations',
        'failed_jobs'  => 'database/migrations',
        'jobs'         => 'database/migrations',
        'sessions'     => 'database/migrations',
    ] as $table => $dir) {
        try {
            if (Schema::hasTable($table)) {
                Schema::drop($table); // frees disk immediately
                $file = collect(glob(base_path($dir . '/*.php')))
                    ->first(fn ($f) => str_contains(basename($f), 'create_' . $table . '_table'));
                if ($file) {
                    (require $file)->up();
                }
                $result[$table] = 'dropped+recreated';
            }
        } catch (\Throwable $e) {
            $result[$table] = 'error: ' . mb_substr($e->getMessage(), 0, 120);
        }
    }

    return response()->json(['reclaimed' => $result, 'at' => now()->toDateTimeString()]);
})->middleware('throttle:10,1');
}

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
