<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shop;
use Marvel\Services\AvailabilityService;
use Marvel\Services\Courier\CourierService;
use Marvel\Services\FulfillmentService;
use Marvel\Services\ItemAssignmentService;
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

    /**
     * POST checkout/estimate { city, pincode?, items: [{product_id, variation_option_id?, quantity?}] }
     * → per-item { unit_price, shipping_charge, expected_delivery_date, fulfillment_mode }
     * + totals. The vendor is chosen by the assignment engine but NEVER named — the
     * customer only ever sees PlantAtHome.
     */
    public function checkoutEstimate(Request $request)
    {
        $city    = $request->filled('city') ? (string) $request->input('city') : null;
        $pincode = $request->filled('pincode') ? (string) $request->input('pincode') : null;
        $items   = array_slice((array) $request->input('items', []), 0, 50); // bound a public endpoint

        $pricing = new PricingService();
        $engine  = new ItemAssignmentService();
        // Courier: live serviceability/rate from the provider for courier-mode lines (C2).
        $courier   = new CourierService();
        $courierOn = $courier->enabled() && $pincode;
        $cod       = $request->boolean('cod');
        $shopPins  = []; // memo: shop_id → pickup pincode

        $ids = collect($items)->pluck('product_id')->filter()->unique()->values();
        $products = Product::whereIn('id', $ids)->get()->keyBy('id');

        $out = [];
        $subtotal = 0.0;
        $shippingTotal = 0.0;
        $maxEtaDays = null;

        foreach ($items as $item) {
            $pid = $item['product_id'] ?? null;
            if (!$pid || !isset($products[$pid])) {
                continue;
            }
            $product = $products[$pid];
            $voId = isset($item['variation_option_id']) ? (int) $item['variation_option_id'] : null;
            $qty  = max(1, (int) ($item['quantity'] ?? 1));

            // Price, shipping AND eta all come from the SAME fulfillable candidate set, so the
            // quote is internally consistent. unit_price = lowest price among vendors that can
            // actually deliver here; shipping/eta from the recommended (top-scored) one.
            $candidates = $engine->candidatesFor((int) $pid, $voId, $qty, $city, $pincode);

            if (empty($candidates)) {
                // Nobody can fulfil this line at the customer's city/pincode/qty — show the
                // catalog reference price but flag it unfulfillable (NOT free, NOT instant) and
                // keep it out of the payable totals.
                $ref = $pricing->sellingPrice($product, $voId, $this->latLng($request));
                $out[] = [
                    'product_id'            => (int) $pid,
                    'variation_option_id'   => $voId,
                    'quantity'              => $qty,
                    'unit_price'            => (float) $ref['price'],
                    'available'             => false,
                    'fulfillable'           => false,
                    'shipping_charge'       => 0.0,
                    'fulfillment_mode'      => null,
                    'expected_delivery_date' => null,
                ];
                continue;
            }

            $best = $candidates[0]; // recommended (top score)
            $prices = array_filter(array_map(fn ($c) => $c['selling_price'], $candidates), fn ($p) => $p !== null);
            $unit = !empty($prices) ? (float) min($prices) : (float) ($best['selling_price'] ?? 0);
            $shipping = (float) ($best['shipping_cost'] ?? 0);
            $eta = $best['eta_days'] ?? null;

            // Courier lines: override shipping/ETA with the cheapest live provider rate when
            // available; on any failure keep the vendor_shipping_rates value (never errors).
            $codAvailable = null;
            if ($courierOn && (($best['fulfillment_mode'] ?? null) === 'courier') && ($best['shop_id'] ?? null)) {
                $shopId = (int) $best['shop_id'];
                if (!array_key_exists($shopId, $shopPins)) {
                    $s = Shop::find($shopId);
                    $addr = (array) ($s->address ?? []);
                    $shopPins[$shopId] = (string) ($s->pickup_postcode ?? ($addr['zip'] ?? ''));
                }
                $weightG = max(1, (int) (($product->weight ?? 0) ?: $courier->defaultPackage()['weight'])) * $qty;
                $svc = $courier->serviceability($shopPins[$shopId], (string) $pincode, $weightG, $cod);
                if ($svc && !empty($svc['cheapest'])) {
                    $shipping = (float) $svc['cheapest']['rate'];
                    $eta = $svc['cheapest']['eta_days'] ?: $eta;
                    $codAvailable = (bool) $svc['cheapest']['cod_available'];
                }
            }

            $subtotal += $unit * $qty;
            $shippingTotal += $shipping;
            if ($eta !== null) {
                $maxEtaDays = max($maxEtaDays ?? 0, (int) $eta);
            }

            $out[] = [
                'product_id'            => (int) $pid,
                'variation_option_id'   => $voId,
                'quantity'              => $qty,
                'unit_price'            => $unit,
                'available'             => true,
                'fulfillable'           => true,
                'shipping_charge'       => round($shipping, 2),
                'fulfillment_mode'      => $best['fulfillment_mode'] ?? null,
                'cod_available'         => $codAvailable,
                'expected_delivery_date' => $eta !== null ? Carbon::now()->addDays((int) $eta)->toDateString() : null,
            ];
        }

        return [
            'items'  => $out,
            'totals' => [
                'subtotal'                => round($subtotal, 2),
                'shipping_total'          => round($shippingTotal, 2),
                'grand_total'             => round($subtotal + $shippingTotal, 2),
                'max_eta_days'            => $maxEtaDays,
                'expected_delivery_date'  => $maxEtaDays !== null ? Carbon::now()->addDays($maxEtaDays)->toDateString() : null,
            ],
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
