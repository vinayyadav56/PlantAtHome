<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Inventory\Http\Requests\UpsertStockRequest;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Vendor/admin stock management + public availability reads. */
final class InventoryController extends ApiController
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    /** PUT /api/v1/inventory/stock — set on-hand for a SKU/vendor. */
    public function setStock(UpsertStockRequest $request): JsonResponse
    {
        $user = $request->user();
        $nurseryId = (string) $request->input('nursery_id');

        // A nursery may only manage its own stock; platform admins any.
        if (! $user->isPlatformAdmin() && (string) $user->nursery_id !== $nurseryId) {
            return $this->fail('FORBIDDEN_SCOPE', 'You can only manage stock for your own nursery.', 403);
        }

        $item = $this->inventory->upsertStock(
            (string) $request->input('sellable_type'),
            (string) $request->input('sellable_uuid'),
            $nurseryId,
            (int) $request->input('qty_on_hand'),
            $request->boolean('track', true),
        );

        return $this->ok([
            'sellable_type' => $item->sellable_type,
            'sellable_uuid' => $item->sellable_uuid,
            'nursery_id'    => $item->nursery_id,
            'qty_on_hand'   => $item->qty_on_hand,
            'qty_reserved'  => $item->qty_reserved,
            'available'     => $item->available(),
        ]);
    }

    /** GET /api/v1/inventory/availability?sellable_type=&sellable_uuid=&nursery_id= */
    public function availability(Request $request): JsonResponse
    {
        foreach (['sellable_type', 'sellable_uuid', 'nursery_id'] as $p) {
            if (! $request->query($p)) {
                return $this->fail('MISSING_PARAM', "Query param '{$p}' is required.", 422, $p);
            }
        }

        $nurseryId = (string) $request->query('nursery_id');
        $available = $this->inventory->available(
            (string) $request->query('sellable_type'),
            (string) $request->query('sellable_uuid'),
            $nurseryId,
        );

        // Public callers get only a coarse in-stock signal — the EXACT per-vendor
        // quantity is competitor-sensitive intel, so it's disclosed only to the
        // owning nursery or a platform admin (mirrors the setStock scope check).
        $user = $request->user();
        $exactAllowed = $user !== null && ($user->isPlatformAdmin() || (string) $user->nursery_id === $nurseryId);

        $payload = [
            'sellable_type' => $request->query('sellable_type'),
            'sellable_uuid' => $request->query('sellable_uuid'),
            'nursery_id'    => $nurseryId,
            'in_stock'      => $available > 0,
        ];
        if ($exactAllowed) {
            $payload['available'] = $available;
        }

        return $this->ok($payload);
    }
}
