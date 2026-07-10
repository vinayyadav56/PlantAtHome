<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Application\CartService;
use App\Modules\Sales\Application\CheckoutService;
use App\Modules\Sales\Http\Resources\SalesResource;
use App\Modules\Sales\Infrastructure\Models\CheckoutSession;
use App\Shared\Application\DomainActionException;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CheckoutController extends ApiController
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly CartService $carts,
    ) {
    }

    /** POST /api/v1/checkout — start checkout (validate → reserve → payment intent). */
    public function start(Request $request): JsonResponse
    {
        $cart = $this->carts->forCustomer($request->user()->uuid);
        $session = $this->checkout->start($cart, $request->user()->uuid, (array) $request->input('address', []));

        return $this->created([
            'checkout_uuid' => $session->uuid,
            'status'        => $session->status,
            'totals'        => $session->totals,
        ]);
    }

    /** POST /api/v1/checkout/{session}/pay — idempotent payment (simulated PSP). */
    public function pay(Request $request, string $session): JsonResponse
    {
        $checkout = CheckoutSession::where('uuid', $session)->first();
        if (! $checkout || $checkout->customer_uuid !== $request->user()->uuid) {
            throw DomainActionException::notFound('Checkout session not found.', 'CHECKOUT_NOT_FOUND');
        }

        $order = $this->checkout->pay(
            $checkout,
            $request->boolean('success', true),
            $request->header('Idempotency-Key'),
        );

        if (! $order) {
            return $this->fail('PAYMENT_FAILED', 'Payment failed; reservations released.', 402);
        }

        return $this->ok(['order' => SalesResource::order($order->load('subOrders.items'))]);
    }
}
