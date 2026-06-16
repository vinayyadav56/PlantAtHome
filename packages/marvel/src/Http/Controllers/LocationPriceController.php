<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Models\Product;
use Marvel\Services\AvailabilityService;
use Marvel\Services\FulfillmentService;
use Marvel\Services\PricingService;

/**
 * Public, customer-facing location-derived price + availability. Single product
 * or a batch (listing pages). P2 returns coarse (representative-vendor) pricing;
 * P3 makes it precise per the supplied lat/lng.
 */
class LocationPriceController extends CoreController
{
    /** GET location-price?product_id=&variation_option_id=&lat=&lng= */
    public function show(Request $request)
    {
        $productId = $request->input('product_id') ?? $request->input('id');
        if (!$productId) {
            return response()->json(['message' => 'product_id is required.'], 422);
        }
        $product = Product::find((int) $productId);
        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $variationOptionId = $request->filled('variation_option_id') ? (int) $request->input('variation_option_id') : null;
        $service = new PricingService();
        $result  = $service->sellingPrice($product, $variationOptionId, $this->latLng($request));

        // Delivery timing for the customer's city: local (same-city) vs courier ETA.
        $fulfillment = null;
        if ($request->filled('city')) {
            $fulfillment = (new FulfillmentService())->fulfillmentFor(
                (int) $product->id,
                $variationOptionId,
                (string) $request->input('city')
            );
        }
        return array_merge(['product_id' => $product->id], $result, ['fulfillment' => $fulfillment]);
    }

    /**
     * POST location-price/batch  { items: [{product_id, variation_option_id?}], lat?, lng? }
     * → { results: { "<product_id>": {price, available, message, ...} } }
     */
    public function batch(Request $request)
    {
        $items  = (array) $request->input('items', []);
        $latLng = $this->latLng($request);
        $service = new PricingService();

        $ids = collect($items)->pluck('product_id')->filter()->unique()->values();
        $products = Product::whereIn('id', $ids)->get()->keyBy('id');

        $results = [];
        foreach ($items as $item) {
            $pid = $item['product_id'] ?? null;
            if (!$pid || !isset($products[$pid])) {
                continue;
            }
            $results[$pid] = $service->sellingPrice(
                $products[$pid],
                isset($item['variation_option_id']) ? (int) $item['variation_option_id'] : null,
                $latLng
            );
        }
        return ['results' => $results];
    }

    /**
     * GET city-availability?city=  — does the customer's city have any vendor-fulfilled
     * products? Lets the storefront decide between the city-first view (+ "Available in
     * your city" badge) and going straight to the global catalog. No seller info exposed.
     */
    public function cityAvailability(Request $request)
    {
        $city = (string) $request->input('city', '');
        $svc  = new AvailabilityService();
        $all   = $svc->availableProductIdsInCity($city, false);
        $local = $svc->availableProductIdsInCity($city, true);
        return [
            'city'             => $city,
            'available_count'  => count($all),
            'local_count'      => count($local),
            'has_availability' => count($all) > 0,
        ];
    }

    private function latLng(Request $request): ?array
    {
        $lat = $request->input('lat');
        $lng = $request->input('lng');
        if (is_numeric($lat) && is_numeric($lng)) {
            return ['lat' => (float) $lat, 'lng' => (float) $lng];
        }
        return null;
    }
}
