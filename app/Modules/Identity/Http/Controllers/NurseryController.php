<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nursery-scoped surface used to prove vendor data isolation in Phase 1. The
 * route is guarded by EnsureNurseryScope, so a nursery user reaching for a
 * different nursery's {nursery} never gets here — they're 403'd at the seam.
 * Later phases hang the real vendor portal (own stock/pricing/sub-orders) off
 * this same scoped path.
 */
final class NurseryController extends ApiController
{
    /** GET /api/v1/nursery/{nursery}/dashboard — own nursery only (admins: any). */
    public function dashboard(Request $request, string $nursery): JsonResponse
    {
        $user = $request->user();

        return $this->ok([
            'nursery_id' => $nursery,
            'scope_ok'   => true,
            'viewer'     => [
                'uuid'          => $user->uuid,
                'role'          => $user->roleName(),
                'own_nursery'   => $user->nursery_id,
                'is_admin_view' => $user->isPlatformAdmin(),
            ],
        ]);
    }
}
