<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\City;
use Marvel\Database\Models\Product;
use Marvel\Enums\ProductStatus;
use Marvel\Services\AvailabilityService;
use Marvel\Services\PricingService;

/**
 * Per-user server-side cart. Lets a logged-in customer's cart follow their account
 * across devices (Android / iOS / web). Stored as the canonical minimal cart
 * (product_id + variation_option_id + quantity); read hydrates each line to a full,
 * still-purchasable product so every client can rebuild its own cart-item shape.
 */
class CartController extends CoreController
{
    /** GET me/cart — the caller's saved cart, product-hydrated (+ its shopping city). */
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

        return response()->json(['data' => [
            'items'            => $items,
            'shopping_city'    => $cart?->shopping_city,
            'shopping_city_id' => $cart?->shopping_city_id,
        ]]);
    }

    /** PUT me/cart — replace the caller's cart with the given lines (+ shopping-city stamp). */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items'                        => 'present|array',
            'items.*.product_id'           => 'required|integer',
            'items.*.variation_option_id'  => 'nullable|integer',
            'items.*.quantity'             => 'required|integer|min:1',
            'shopping_city'                => 'nullable|string|max:120',
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

        // Shopping-city stamp: the cart belongs to exactly one city. Resolve the id from
        // the cities canon when the name matches (nullable — old clients send no city).
        $fill = ['items' => $items];
        if (array_key_exists('shopping_city', $data)) {
            $cityName = $data['shopping_city'] !== null ? trim((string) $data['shopping_city']) : null;
            $canon = null;
            if ($cityName) {
                $normalized = app(AvailabilityService::class)->normalizeCityKey($cityName);
                $canon = City::query()->whereRaw('LOWER(name) = ?', [$normalized])->first();
            }
            $fill['shopping_city'] = $cityName ?: null;
            $fill['shopping_city_id'] = $canon?->id;
        }

        Cart::updateOrCreate(
            ['user_id' => $request->user()->id],
            $fill
        );

        return response()->json(['data' => [
            'items'            => $items,
            'shopping_city'    => $fill['shopping_city'] ?? null,
            'shopping_city_id' => $fill['shopping_city_id'] ?? null,
        ]]);
    }

    /**
     * POST cart/validate-city — the change-shopping-city cart migration. For each line:
     * still available in the target city? If yes, return the city's price (server-
     * authoritative repricing); if no, it goes to `unavailable` so the client can drop it
     * and show what was removed. Public (guest carts live client-side) + throttled at the
     * route. Fail-open per line: an internal fault keeps the item (never silently empties
     * a customer's cart because of an outage).
     */
    public function validateCity(Request $request, AvailabilityService $availability): JsonResponse
    {
        $data = $request->validate([
            'city'                         => 'required|string|max:120',
            'items'                        => 'present|array|max:100',
            'items.*.product_id'           => 'required|integer',
            'items.*.variation_option_id'  => 'nullable|integer',
            'items.*.quantity'             => 'nullable|integer|min:1',
        ]);

        $city = trim($data['city']);

        // City availability for the target city: null = unmapped-but-serviceable city
        // (full catalog ⇒ everything stays); otherwise a product_id query (strict list,
        // which is EMPTY for a non-serviceable city). Materialize once for the loop.
        $scope = $availability->cityScopeProductIds($city);
        $allowed = $scope === null
            ? null
            : array_map('intval', $scope->pluck('product_id')->all());

        // Reprice the kept lines to the target city's uniform selling price.
        $pricing = new PricingService();
        $repriced = [];
        try {
            $lines = array_map(fn ($l) => [
                'product_id'          => (int) $l['product_id'],
                'variation_option_id' => isset($l['variation_option_id']) ? (int) $l['variation_option_id'] : null,
                'order_quantity'      => max(1, (int) ($l['quantity'] ?? 1)),
            ], $data['items']);
            foreach ($pricing->repriceLines($lines, null, $city) as $r) {
                $key = ($r['product_id'] ?? 0) . ':' . ($r['variation_option_id'] ?? '');
                $repriced[$key] = $r;
            }
        } catch (\Throwable $e) {
            // pricing fail-open — availability verdicts still apply, prices just omitted
        }

        $available = [];
        $unavailable = [];
        foreach ($data['items'] as $l) {
            $pid = (int) $l['product_id'];
            $ok = $allowed === null || in_array($pid, $allowed, true);
            $entry = [
                'product_id'          => $pid,
                'variation_option_id' => isset($l['variation_option_id']) ? (int) $l['variation_option_id'] : null,
                'quantity'            => max(1, (int) ($l['quantity'] ?? 1)),
            ];
            if ($ok) {
                $key = $pid . ':' . ($entry['variation_option_id'] ?? '');
                $entry['unit_price'] = $repriced[$key]['unit_price'] ?? null;
                $available[] = $entry;
            } else {
                $unavailable[] = $entry;
            }
        }

        return response()->json(['data' => [
            'city'        => $city,
            'available'   => $available,
            'unavailable' => $unavailable,
        ]]);
    }
}
