<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    // protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });

        // Tight limiter for credential endpoints (login/refresh/reset) to blunt
        // brute-force / token-guessing. Disabled under testing so the suite's
        // many logins don't trip it.
        //
        // TWO independent limits so the control holds behind any proxy topology:
        //  - by EMAIL (credential): 10/min per account, IP-INDEPENDENT — this is
        //    what actually stops credential-stuffing an account, and it works
        //    even when the client IP rotates across proxy hops (e.g. Railway's
        //    edge resolves varying $request->ip(), which silently defeated the
        //    old email|ip key — the counter never accumulated on one key).
        //  - by IP: 30/min per source to blunt spray across many accounts.
        RateLimiter::for('auth', function (Request $request) {
            if (app()->environment('testing')) {
                return Limit::none();
            }

            $email = strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(10)->by('auth-email:'.($email ?: 'none')),
                Limit::perMinute(30)->by('auth-ip:'.$request->ip()),
            ];
        });
    }
}
