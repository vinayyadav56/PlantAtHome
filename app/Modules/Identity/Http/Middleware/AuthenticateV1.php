<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Application\AuthService;
use App\Modules\Identity\Application\Exceptions\InvalidTokenException;
use App\Shared\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a v1 request from its Bearer access token and binds the
 * resolved IdentityUser onto the request ($request->user()). Any failure short-
 * circuits with a 401 in the standard error envelope — downstream role/scope
 * middleware can then assume an authenticated user is present.
 */
class AuthenticateV1
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return ApiResponse::message('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        try {
            $user = $this->auth->userFromAccessToken($token);
        } catch (InvalidTokenException $e) {
            return ApiResponse::message($e->errorCode(), $e->getMessage(), 401);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
