<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Shared\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permission gate. Usage: `->middleware('v1.can:catalog.manage')` (accepts
 * several — passes if the user holds ANY). Must run after AuthenticateV1.
 * Authorization is on explicit permissions (super_admin implicitly holds all),
 * so re-assigning a capability is a data change in the AccessMatrix, not a code
 * change — this is the seam other modules gate their writes through.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::message('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        return ApiResponse::message('FORBIDDEN', 'You do not have permission to perform this action.', 403);
    }
}
