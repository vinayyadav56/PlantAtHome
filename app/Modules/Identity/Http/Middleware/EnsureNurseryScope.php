<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Shared\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vendor data isolation. Usage: `->middleware('v1.nursery')` (or
 * `v1.nursery:shop` to read a differently-named route param). A nursery user may
 * only act within their own nursery_id; a mismatch is 403. Platform admins
 * (admin/super_admin) bypass scoping and may act on any nursery.
 *
 * This is the guardrail behind "a vendor can only ever touch its own data"
 * (Section 13) — the single choke point every nursery-scoped route passes
 * through, so isolation can't be forgotten per-controller.
 */
class EnsureNurseryScope
{
    public function handle(Request $request, Closure $next, string $routeParam = 'nursery'): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::message('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        // Platform admins are not nursery-scoped.
        if ($user->isPlatformAdmin()) {
            return $next($request);
        }

        $requestedScope = (string) $request->route($routeParam);

        if (empty($user->nursery_id) || $user->nursery_id !== $requestedScope) {
            return ApiResponse::message(
                'FORBIDDEN_SCOPE',
                'You can only access data belonging to your own nursery.',
                403,
            );
        }

        return $next($request);
    }
}
