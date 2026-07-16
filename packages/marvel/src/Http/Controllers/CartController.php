<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Product;
use Marvel\Enums\ProductStatus;

/**
 * Per-user server-side cart. Lets a logged-in customer's cart follow their account
 * across devices (Android / iOS / web). Stored as the canonical minimal cart
 * (product_id + variation_option_id + quantity); read hydrates each line to a full,
 * still-purchasable product so every client can rebuild its own cart-item shape.
 */
class CartController extends CoreController
{
    /** GET me/cart — the caller's saved cart, product-hydrated. */
    public function show(Request $request): JsonResponse
    {
        $cart = Cart::where('user_id', $request->user()->id)->first();
        $stored = is_array($cart?->items) ? $cart->items : [];

        $items = [];
        foreach ($stored as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $voId = isset($line['variation_option_id']) && $line['variation_option_id'] !== null
                ? (int) $line['variation_option_id']
                : null;
            if (!$productId) {
                continue;
            }
            // Only surface products that still exist and are live — drop stale lines silently.
            $product = Product::with(['variation_options', 'type'])
                ->where('id', $productId)
                ->where('status', ProductStatus::PUBLISH)
                ->first();
            if (!$product) {
                continue;
            }
            $items[] = [
                'product'             => $product,
                'variation_option_id' => $voId,
                'quantity'            => $qty,
            ];
        }

        return response()->json(['data' => ['items' => $items]]);
    }

    /** PUT me/cart — replace the caller's cart with the given lines. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items'                        => 'present|array',
            'items.*.product_id'           => 'required|integer',
            'items.*.variation_option_id'  => 'nullable|integer',
            'items.*.quantity'             => 'required|integer|min:1',
        ]);

        // Normalize + de-dupe by (product_id, variation_option_id) so the stored cart
        // stays canonical regardless of what the client sends.
        $byKey = [];
        foreach ($data['items'] as $line) {
            $pid = (int) $line['product_id'];
            $voId = isset($line['variation_option_id']) && $line['variation_option_id'] !== null
                ? (int) $line['variation_option_id']
                : null;
            $key = $pid . ':' . ($voId ?? '');
            $byKey[$key] = [
                'product_id'          => $pid,
                'variation_option_id' => $voId,
                'quantity'            => max(1, (int) $line['quantity']),
            ];
        }
        $items = array_values($byKey);

        Cart::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['items' => $items]
        );

        return response()->json(['data' => ['items' => $items]]);
    }
}
