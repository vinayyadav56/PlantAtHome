<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Inventory\Http\Requests\ReserveRequest;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;

/**
 * Reservation lifecycle: reserve (atomic, no oversell) → commit (deduct) or
 * release. Checkout (Phase 8) drives these; exposed here for management + tests.
 */
final class ReservationController extends ApiController
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    /** POST /api/v1/inventory/reservations */
    public function reserve(ReserveRequest $request): JsonResponse
    {
        $reservations = $this->inventory->reserve(
            $request->input('items'),
            (string) $request->input('checkout_session_id'),
            (int) $request->input('ttl_seconds', InventoryService::DEFAULT_TTL),
        );

        return $this->created([
            'checkout_session_id' => $request->input('checkout_session_id'),
            'reserved'            => count($reservations),
            'reservations'        => collect($reservations)->map(fn ($r) => [
                'uuid' => $r->uuid, 'qty' => $r->qty, 'expires_at' => $r->expires_at->toIso8601String(),
            ])->all(),
        ]);
    }

    /** POST /api/v1/inventory/reservations/{session}/commit */
    public function commit(string $session): JsonResponse
    {
        return $this->ok(['committed' => $this->inventory->commit($session)]);
    }

    /** POST /api/v1/inventory/reservations/{session}/release */
    public function release(string $session): JsonResponse
    {
        return $this->ok(['released' => $this->inventory->release($session)]);
    }
}
