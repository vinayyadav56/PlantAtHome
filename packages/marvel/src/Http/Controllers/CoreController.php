<?php

namespace Marvel\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Marvel\Enums\Permission;

class CoreController extends BaseController
{
    use AuthorizesRequests;
    use DispatchesJobs;
    use ValidatesRequests;

    /**
     * Is this request the admin dashboard (or a vendor back-office), as opposed
     * to a storefront shopper?
     *
     * The distinction that matters: "has a Bearer token" is NOT it. A signed-in
     * CUSTOMER sends one too, and an invalid token satisfies mere presence — so
     * gating catalogue visibility on token presence hands every shopper the
     * unfiltered admin view. These routes are public, so the user is resolved
     * explicitly through the sanctum guard rather than middleware.
     */
    protected function isCatalogStaff(Request $request): bool
    {
        try {
            $user = $request->user() ?? $request->user('sanctum');
            return $user && (
                $user->hasPermissionTo(Permission::SUPER_ADMIN)
                || $user->hasPermissionTo(Permission::STORE_OWNER)
                || $user->hasPermissionTo(Permission::STAFF)
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
}
