<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Application\OrderService;
use App\Modules\Sales\Http\Resources\SalesResource;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Order reads + sub-order lifecycle. The customer sees the parent order; a
 * nursery only ever sees/acts on its OWN sub-orders (vendor-hidden split).
 */
final class OrderController extends ApiController
{
    public function __construct(private readonly OrderService $orders)
    {
    }

    /** GET /api/v1/orders/{order} — customer parent-order view. */
    public function show(Request $request, string $order): JsonResponse
    {
        $model = $this->orders->customerOrder($order, $request->user()->uuid, $request->user()->isPlatformAdmin());

        return $this->ok(SalesResource::order($model));
    }

    /** GET /api/v1/nursery/sub-orders — a nursery's own fulfillment queue. */
    public function nurseryQueue(Request $request): JsonResponse
    {
        $subs = $this->orders->nurserySubOrders((string) $request->user()->nursery_id);

        return $this->ok($subs->map(fn ($s) => SalesResource::subOrder($s))->all());
    }

    /** POST /api/v1/nursery/sub-orders/{sub}/transition {to} */
    public function transition(Request $request, string $sub): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'string']]);
        $subOrder = $this->orders->nurserySubOrder($sub, (string) $request->user()->nursery_id, $request->user()->isPlatformAdmin());
        $updated = $this->orders->transitionSubOrder($subOrder, $data['to'], $request->user()->uuid);

        return $this->ok(SalesResource::subOrder($updated->load('items')));
    }

    /** POST /api/v1/nursery/sub-orders/{sub}/refund */
    public function refund(Request $request, string $sub): JsonResponse
    {
        $subOrder = $this->orders->nurserySubOrder($sub, (string) $request->user()->nursery_id, $request->user()->isPlatformAdmin());
        $updated = $this->orders->refundSubOrder($subOrder, $request->user()->uuid);

        return $this->ok(SalesResource::subOrder($updated->load('items')));
    }
}
