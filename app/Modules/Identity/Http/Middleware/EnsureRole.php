<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Shared\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role gate. Usage: `->middleware('v1.role:admin,super_admin')`. Must run after
 * AuthenticateV1. Rejects with 403 when the authenticated user's role is not in
 * the allowed set.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::message('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        if (! $user->hasAnyRole($roles)) {
            return ApiResponse::message('FORBIDDEN', 'You do not have permission to access this resource.', 403);
        }

        return $next($request);
    }
}
