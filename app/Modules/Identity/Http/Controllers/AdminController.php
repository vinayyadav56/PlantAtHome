<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A minimal admin-only surface used to prove role enforcement in Phase 1 (a
 * nursery_staff token must be rejected here). Real admin capabilities land in
 * later phases; this is the guarded seam.
 */
final class AdminController extends ApiController
{
    /** GET /api/v1/admin/ping — requires admin or super_admin. */
    public function ping(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->ok([
            'area'  => 'admin',
            'ok'    => true,
            'actor' => ['uuid' => $user->uuid, 'role' => $user->roleName()],
        ]);
    }
}
