<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * SECURITY/correctness: the app always sits behind a managed edge proxy (Railway on staging,
     * nginx/ELB on EC2 prod) and is never reachable directly, so trust the proxy and resolve the
     * real client IP from X-Forwarded-For. Without this, $request->ip() returns the platform
     * egress IP and every IP-keyed rate limiter (login/OTP/etc) keys per-proxy = effectively
     * global, 429-ing all users at once.
     *
     * @var array|string|null
     */
    protected $proxies = '*';

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

}
