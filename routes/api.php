<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

/*
Route::get('/health', function() { try { DB::connection()->getPdo(); return response()->json(['status'=>'ok','db'=>'connected','env'=>config('app.env')]); } catch(Exception $e) { return response()->json(['status'=>'error','db'=>$e->getMessage()],500); } });
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});
