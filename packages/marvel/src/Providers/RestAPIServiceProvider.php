<?php

namespace Marvel\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;


class RestApiServiceProvider extends ServiceProvider
{

    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->loadRoutes();
    }

    public function loadRoutes(): void
    {
        // SECURITY: attach the framework `throttle:api` (60/min/IP) baseline to the ENTIRE marvel
        // API surface. These routes are mounted here (not via the app `api` middleware group), so
        // without this the global limiter never applied and every endpoint without its own
        // `throttle:` was completely unthrottled (credential/OTP brute force, cost-exhaustion).
        // Per-route tighter throttles on auth/OTP/AI routes layer on top of this.
        Route::prefix('api')->middleware(['throttle:api', \Marvel\Http\Middleware\LogRequests::class])->group(function () {
            $this->loadRoutesFrom(__DIR__ . '/../Rest/Routes.php');
        });
    }
}
