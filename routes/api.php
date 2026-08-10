<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
| Health checks — three intents, so a load balancer / orchestrator can tell "the process is
| up" apart from "this instance can actually serve traffic":
|
|   /health       back-compat: liveness + a DB probe (unchanged shape, existing monitors keep working)
|   /health/live  LIVENESS ONLY — the process answered. NO external deps, so a transient DB/Redis
|                 blip never makes an LB kill a healthy instance. Always 200 while PHP is serving.
|   /health/ready READINESS — DB + cache(Redis) reachable. 200 when the instance can serve, 503
|                 otherwise, so the LB drains it instead of sending it traffic it can't complete.
*/

Route::get('/health', function () {
    $db = 'unknown';
    try {
        DB::connection()->getPdo();
        $db = 'connected';
    } catch (\Exception $e) {
        $db = 'unavailable';
    }
    return response()->json(['status' => 'ok', 'db' => $db, 'env' => config('app.env')]);
});

// Liveness — deliberately dependency-free.
Route::get('/health/live', fn () => response()->json(['status' => 'ok']));

// Readiness — probes the dependencies an instance needs to complete a request.
Route::get('/health/ready', function () {
    $checks = [];

    try {
        DB::connection()->getPdo();
        DB::connection()->select('select 1');
        $checks['db'] = 'ok';
    } catch (\Throwable $e) {
        $checks['db'] = 'fail';
    }

    try {
        // Round-trip the configured cache store (Redis on prod). A write+read proves the
        // link, not just that the client constructed.
        $probe = 'health:ready:' . bin2hex(random_bytes(4));
        Cache::put($probe, '1', 5);
        $checks['cache'] = Cache::get($probe) === '1' ? 'ok' : 'fail';
        Cache::forget($probe);
    } catch (\Throwable $e) {
        $checks['cache'] = 'fail';
    }

    $ready = !in_array('fail', $checks, true);

    return response()->json(
        ['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks],
        $ready ? 200 : 503
    );
});

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// sanctum, not the token 'api' guard — every other authed route here uses
// sanctum; the odd guard out returned 500s instead of 401s for bad tokens.
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
