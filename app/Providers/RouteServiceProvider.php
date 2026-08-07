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

        // OTP send/verify. Same two-axis shape as 'auth' and for the same reason, but keyed on
        // the PHONE NUMBER — the OTP routes were throttle:10,1, i.e. by IP only, so an attacker
        // rotating source addresses got unlimited attempts at one number's code. That matters
        // more here than for passwords: an OTP is a handful of digits, and the code itself is
        // verified by MSG91, so this application-layer limit is the only per-number control we own.
        //
        // The phone axis is IP-INDEPENDENT, so it holds even when TrustProxies resolves a
        // spoofable client address (see app/Http/Middleware/TrustProxies.php).
        RateLimiter::for('otp', function (Request $request) {
            if (app()->environment('testing')) {
                return Limit::none();
            }

            return self::otpLimits($request);
        });
    }

    /**
     * The OTP limits themselves, split out from the registration above so the testing bypass
     * does not make them untestable — a rate limit nobody can assert on is how the IP-only
     * version survived this long.
     *
     * @return array<int, Limit>
     */
    public static function otpLimits(Request $request): array
    {
        return [
            Limit::perMinute(5)->by('otp-phone:'.(self::otpPhoneKey($request) ?: 'none')),
            Limit::perMinute(20)->by('otp-ip:'.$request->ip()),
        ];
    }

    /**
     * Normalised phone key. +91-98765 43210, 919876543210 and 9876543210 must share one
     * counter, or the limit is bypassed by simply reformatting the same number.
     */
    public static function otpPhoneKey(Request $request): string
    {
        $raw = (string) ($request->input('phone_number') ?: $request->input('phone'));
        $digits = (string) preg_replace('/\D+/', '', $raw);

        return $digits === '' ? '' : substr($digits, -10);
    }
}
