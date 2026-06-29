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
        // NOTE: deliberately NOT attaching a group-wide `throttle:api` here. The storefront's
        // browser calls reach this API through the Vercel /rest-api proxy and SSR calls come from
        // Vercel's servers, so a single IP-keyed 60/min group bucket would collapse ALL web
        // traffic into one limiter (and would cap the high-throughput webhook/AI-callback routes).
        // Abuse-prone endpoints (auth/OTP/AI/upload) instead carry tight PER-ROUTE throttles in
        // Rest/Routes.php, which (with TrustProxies now trusting the platform proxy) key on the
        // real client IP.
        Route::prefix('api')->middleware([
            // FIRST: resolve request language (?language= or Accept-Language) so the
            // translation overlay localizes every downstream response.
            \Marvel\Http\Middleware\ResolveLanguage::class,
            \Marvel\Http\Middleware\SecurityHeaders::class,
            \Marvel\Http\Middleware\LogRequests::class,
        ])->group(function () {
            $this->loadRoutesFrom(__DIR__ . '/../Rest/Routes.php');
        });
    }
}
