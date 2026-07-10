<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Application\CartService;
use App\Modules\Sales\Http\Requests\AddItemRequest;
use App\Modules\Sales\Http\Resources\SalesResource;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CartController extends ApiController
{
    public function __construct(private readonly CartService $cart)
    {
    }

    /** GET /api/v1/cart — the current customer's open cart. */
    public function show(Request $request): JsonResponse
    {
        $cart = $this->cart->forCustomer($request->user()->uuid, $request->query('city'));

        return $this->ok(SalesResource::cart($cart->load('items')));
    }

    /** POST /api/v1/cart/items — add a validated, priced line. */
    public function addItem(AddItemRequest $request): JsonResponse
    {
        $cart = $this->cart->forCustomer($request->user()->uuid, $request->input('city'));
        $this->cart->addItem(
            $cart,
            (string) $request->input('variant_uuid'),
            (string) $request->input('nursery_id'),
            (array) $request->input('selection', []),
            (int) $request->input('qty', 1),
            $request->input('city'),
        );

        return $this->created(SalesResource::cart($cart->load('items')));
    }

    /** DELETE /api/v1/cart/items/{item} */
    public function removeItem(Request $request, string $item): JsonResponse
    {
        $cart = $this->cart->forCustomer($request->user()->uuid);
        $this->cart->removeItem($cart, $item);

        return $this->ok(SalesResource::cart($cart->load('items')));
    }
}
