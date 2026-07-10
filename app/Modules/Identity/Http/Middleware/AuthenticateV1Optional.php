<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Application\AuthService;
use App\Modules\Identity\Application\Exceptions\InvalidTokenException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Best-effort authentication: if a valid Bearer token is present, bind the user
 * onto the request; otherwise continue as a guest. Used by public reads that
 * behave differently for privileged callers (e.g. an admin may see draft
 * products a guest may not). Never rejects — an invalid token is treated as no
 * token, so guests are unaffected.
 */
class AuthenticateV1Optional
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token) {
            try {
                $user = $this->auth->userFromAccessToken($token);
                $request->setUserResolver(fn () => $user);
            } catch (InvalidTokenException) {
                // Ignore — treat as an anonymous request.
            }
        }

        return $next($request);
    }
}
