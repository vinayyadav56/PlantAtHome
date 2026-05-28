<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

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

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'service' => 'plantathome-api'], 200);
});

// One-time bootstrap: sets app_settings.trust=true and activates admin user.
// Safe to call multiple times (idempotent). Remove after initial setup is confirmed.
Route::get('/bootstrap-setup', function () {
    $results = [];
    try {
        // Ensure settings record exists with app_settings.trust = true
        $settings = \Marvel\Database\Models\Settings::getData();
        if (!$settings) {
            \Illuminate\Support\Facades\Artisan::call('db:seed', [
                '--class' => 'Marvel\\Database\\Seeders\\SettingsSeeder',
                '--force' => true,
            ]);
            $settings = \Marvel\Database\Models\Settings::getData();
            $results[] = 'settings: seeded';
        }
        if ($settings) {
            $opts = $settings->options ?? [];
            $opts['app_settings'] = ['trust' => true, 'last_checking_time' => now()->toISOString()];
            $settings->update(['options' => $opts]);
            \Illuminate\Support\Facades\Cache::flush();
            $results[] = 'settings.app_settings.trust = true';
        } else {
            $results[] = 'ERROR: could not create settings record';
        }
    } catch (\Exception $e) {
        $results[] = 'settings error: ' . $e->getMessage();
    }

    try {
        // Ensure permissions and roles exist
        \app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['super_admin', 'customer', 'store_owner', 'staff'] as $perm) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $perm]);
        }
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin'])
            ->syncPermissions(['super_admin', 'store_owner', 'customer']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'customer'])
            ->syncPermissions(['customer']);
        $results[] = 'permissions + roles: ok';
    } catch (\Exception $e) {
        $results[] = 'roles error: ' . $e->getMessage();
    }

    try {
        $adminEmail = env('ADMIN_EMAIL', 'yadavvinay9996@gmail.com');
        $adminPassword = env('ADMIN_PASSWORD', 'Admin@1234');
        $adminName = env('ADMIN_NAME', 'Admin');
        $user = \Marvel\Database\Models\User::where('email', $adminEmail)->first();
        if (!$user) {
            $user = \Marvel\Database\Models\User::create([
                'name' => $adminName, 'email' => $adminEmail,
                'password' => \Illuminate\Support\Facades\Hash::make($adminPassword),
                'is_active' => true,
            ]);
            $results[] = "admin created: {$adminEmail}";
        } else {
            $user->is_active = true;
            $user->email_verified_at = now();
            $user->save();
            $results[] = "admin activated: {$adminEmail}";
        }
        $user->givePermissionTo(['super_admin', 'store_owner', 'customer']);
        $user->assignRole('super_admin');
        $results[] = 'admin roles assigned';
    } catch (\Exception $e) {
        $results[] = 'admin error: ' . $e->getMessage();
    }

    return response()->json(['status' => 'ok', 'results' => $results]);
});
