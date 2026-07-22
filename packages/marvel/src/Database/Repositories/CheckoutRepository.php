<?php


namespace Marvel\Database\Repositories;

use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Tax;
use Marvel\Database\Models\Shipping;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Variation;
use Marvel\Traits\WalletsTrait;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CheckoutRepository
{
    use WalletsTrait;

    public function verify($request)
    {
        if ($request['customer_id']) {
            try {
                $user = User::findOrFail($request->customer_id);
            } catch (\Throwable $th) {
                throw new ModelNotFoundException(NOT_FOUND);
            }
        } else {
            $user = $request->user() ?? null;
        }
        $wallet = $user->wallet ?? null;
        $settings = Settings::getData();
        $minimumOrderAmount = isset($settings['options']['minimumOrderAmount']) ? $settings['options']['minimumOrderAmount'] : 0;
        $unavailable_products = $this->checkStock($request['products']);

        // Operations Control Center — block any cart line whose vertical is
        // currently unavailable in the shipping city. FAIL OPEN: no city ⇒ no gate.
        $blocked_verticals = [];
        $shipCity = is_array($request['shipping_address'] ?? null) ? ($request['shipping_address']['city'] ?? null) : null;
        if (!empty($shipCity)) {
            try {
                $availSvc = app(\Marvel\Services\ServiceAvailabilityService::class);
                $ids = collect($request['products'])->pluck('product_id')->filter()->unique()->values()->all();
                if (!empty($ids)) {
                    foreach (Product::with('type')->whereIn('id', $ids)->get(['id', 'type_id']) as $p) {
                        $slug = optional($p->type)->slug;
                        if (!$slug) {
                            continue;
                        }
                        $res = $availSvc->resolve($slug, (string) $shipCity);
                        if (!$res['available']) {
                            $unavailable_products[] = $p->id;
                            $blocked_verticals[$slug] = $res['message'];
                        }
                    }
                    $unavailable_products = array_values(array_unique($unavailable_products));
                }
            } catch (\Throwable $e) {
                // fail open
            }
        }

        // City-first availability — a cart line must belong to the shipping city.
        // cityScopeProductIds() returns null for a serviceable-but-unmapped city
        // (full catalog ⇒ no gate); a restricted set for a vendor-mapped city; and an
        // empty set for a non-serviceable city ⇒ every line flagged. FAIL OPEN on fault.
        if (!empty($shipCity)) {
            try {
                $availSvc = app(\Marvel\Services\AvailabilityService::class);
                $scope = $availSvc->cityScopeProductIds((string) $shipCity);
                if ($scope !== null) {
                    $allowed = array_flip(array_map('intval', $scope->pluck('product_id')->all()));
                    foreach (collect($request['products'])->pluck('product_id')->filter()->unique() as $pid) {
                        if (!isset($allowed[(int) $pid])) {
                            $unavailable_products[] = (int) $pid;
                        }
                    }
                    $unavailable_products = array_values(array_unique($unavailable_products));
                }
            } catch (\Throwable $e) {
                // fail open
            }
        }

        // Shopping-City gate (redesign): when the client declares its shopping city, the
        // delivery address must belong to that city. Enforced ONLY when `shopping_city`
        // is sent (old clients unaffected). verify() reports it structured so the UI can
        // show the choose-another-address / change-city dialog; storeOrder() hard-blocks.
        $city_mismatch = $this->shoppingCityMismatch($request);
        if ($city_mismatch !== null) {
            $unavailable_products = array_values(array_unique(array_merge(
                array_map('intval', $unavailable_products),
                collect($request['products'])->pluck('product_id')->filter()->map(fn ($i) => (int) $i)->all()
            )));
        }

        // Delivery Coverage hard gate (pincode-level, flag-gated OFF by default):
        // when `coverageCheckoutGate` is on and vendors have coverage rules, a cart
        // line whose shop does not cover the shipping pincode is unavailable.
        // FAIL OPEN on any missing precondition or fault (see applyCoverageGate).
        $coverage = null;
        $gate = $this->applyCoverageGate((array) ($request['products'] ?? []), $this->shippingZip($request));
        if (!empty($gate['blocked'])) {
            $unavailable_products = array_values(array_unique(array_merge(
                array_map('intval', $unavailable_products),
                $gate['blocked']
            )));
            $coverage = $gate['coverage'];
        }

        // Server-authoritative pricing: reprice vendor-supplied products to the city
        // selling price (max vendor rate + PlantAtHome margin) so the previewed total
        // matches what the order will charge (products without vendor inventory are
        // untouched). $shipCity threads the city into the margin resolution.
        $request['products'] = (new \Marvel\Services\PricingService())
            ->repriceLines((array) $request['products'], $this->customerLatLng($request), $shipCity ? (string) $shipCity : null);
        $request['amount'] = collect($request['products'])->sum('subtotal');

        $amount = $this->getOrderAmount($request, $unavailable_products);
        $shipping_charge = !empty($settings['options']['freeShipping']) && $settings['options']['freeShippingAmount'] <= $amount ? 0 : $this->calculateShippingCharge($request, $amount);
        // Fee cutover: when the Delivery Optimizer is enabled, the customer pays ONE
        // consolidated flat fee (free above the same threshold) instead of the per-product
        // sum. Null (flag off / any failure) keeps the legacy charge above, byte-identical.
        $optimizerFee = $this->optimizerFlatFee((float) $amount);
        if ($optimizerFee !== null) {
            $shipping_charge = $optimizerFee;
        }
        $tax = $this->calculateTax($request, $shipping_charge, $amount);
        $total = $amount + $tax + $shipping_charge;
        // Only enforce the minimum-order-amount on a fully-available cart. When
        // some lines are unavailable (out of stock, or a vertical the Operations
        // Control Center has disabled) they're excluded from $amount, which can
        // drop the total below the minimum and surface a misleading "Minimum
        // order amount" error instead of the real "item unavailable" reason. Let
        // the storefront show the unavailable items (returned below) first; the
        // next verify — after the shopper removes them — re-applies this check.
        if (empty($unavailable_products) && $total < $minimumOrderAmount) {
            throw new HttpException(400, 'Minimum order amount is ' . $minimumOrderAmount);
        }
        $response = [
            'total_tax'            => $tax,
            'shipping_charge'      => $shipping_charge,
            // Delivery Optimizer (additive, flag-gated): FIRM consolidated shipments at
            // checkout. Metadata only for now — `shipping_charge` above is unchanged until
            // an explicit cutover, so the charged total stays byte-identical. Never throws.
            'optimized'            => $this->optimizedCheckout($request, (float) $amount, (bool) ($request['isFullWalletPayment'] ?? false)),
            'unavailable_products' => $unavailable_products,
            // Shopping-City gate: null when OK / not applicable; else
            // { code, shopping_city, address_city } for the storefront mismatch dialog.
            'city_mismatch'        => $city_mismatch,
            // Operations Control Center — { vertical_slug: message } for blocked
            // lines. Cast to object so the shape is stable (always {} when empty).
            'blocked_verticals'    => (object) $blocked_verticals,
            // Server-authoritative amount + repriced lines (margin-over-cost for
            // vendor-cost-sheet products); the storefront uses these for the total.
            'amount'               => round((float) $amount, 2),
            'priced_products'      => array_map(fn ($p) => [
                'product_id'          => $p['product_id'] ?? null,
                'variation_option_id' => $p['variation_option_id'] ?? null,
                'unit_price'          => $p['unit_price'] ?? null,
                'subtotal'            => $p['subtotal'] ?? null,
            ], (array) $request['products']),
            'wallet_amount' => isset($wallet->available_points) ? $wallet->available_points : 0,
            'wallet_currency' => isset($wallet->available_points) ? $this->walletPointsToCurrency($wallet->available_points) : 0
        ];
        // Additive: only present when the coverage gate actually blocked lines,
        // so existing consumers of the verify payload never see a new key otherwise.
        if (!empty($coverage)) {
            $response['coverage'] = $coverage;
        }
        return $response;
    }

    /** Shipping pincode from the verify payload (zip | pincode | postal_code, possibly nested under address). */
    protected function shippingZip($request): ?string
    {
        $ship = is_array($request['shipping_address'] ?? null) ? $request['shipping_address'] : [];
        $addr = (array) ($ship['address'] ?? $ship);
        $zip = $addr['zip'] ?? $addr['pincode'] ?? $addr['postal_code'] ?? ($ship['zip'] ?? null);
        $zip = preg_replace('/\D+/', '', (string) $zip);
        return $zip !== '' ? $zip : null;
    }

    /**
     * Shopping-City gate (redesign): the delivery address must belong to the declared
     * shopping city. Returns null when OK or NOT APPLICABLE (no shopping_city sent —
     * old clients unaffected), else the structured mismatch payload.
     *
     * Address-city resolution is SERVER-authoritative, most-trustworthy first:
     *  1. postal master by shipping pincode (19k-pin canon; typed cities can lie),
     *  2. the address's server-verified reverse-geocoded city (rg_city),
     *  3. the typed shipping_address.city (legacy fallback).
     * Both sides normalize through AvailabilityService::normalizeCityKey (aliases:
     * Gurgaon→Gurugram etc.), so exonym/endonym never falsely blocks.
     */
    public function shoppingCityMismatch($request): ?array
    {
        try {
            $shoppingCity = trim((string) ($request['shopping_city'] ?? ''));
            if ($shoppingCity === '') {
                return null; // gate applies only when the client declares a shopping city
            }
            $ship = (array) ($request['shipping_address'] ?? []);

            $addressCity = null;
            $zip = $this->shippingZip($request);
            if ($zip) {
                try {
                    $geo = \Illuminate\Support\Facades\DB::table('postal_codes')
                        ->leftJoin('cities', 'cities.id', '=', 'postal_codes.city_id')
                        ->where('postal_codes.pincode', $zip)
                        ->value('cities.name');
                    $addressCity = $geo ?: null;
                } catch (\Throwable $e) {
                    // postal master unavailable — fall through
                }
            }
            $addressCity = $addressCity ?: ($ship['rg_city'] ?? null) ?: ($ship['city'] ?? null);
            if (!$addressCity) {
                return null; // nothing to compare — other gates handle unserviceable cases
            }

            $norm = app(\Marvel\Services\AvailabilityService::class);
            if ($norm->normalizeCityKey((string) $addressCity) === $norm->normalizeCityKey($shoppingCity)) {
                return null;
            }
            return [
                'code'          => 'SHOPPING_CITY_MISMATCH',
                'shopping_city' => $shoppingCity,
                'address_city'  => (string) $addressCity,
                'message'       => sprintf(
                    'The selected delivery address belongs to %s, but you are currently shopping in %s. Please select a %s address or change your shopping city.',
                    $addressCity,
                    $shoppingCity,
                    $shoppingCity
                ),
            ];
        } catch (\Throwable $e) {
            return null; // never break checkout on a gate fault
        }
    }

    /**
     * Delivery Coverage checkout gate. Guard chain — each miss skips the gate
     * silently (fail open): settings flag `coverageCheckoutGate` truthy, a
     * >= 6-digit shipping pincode, the V2 coverage service resolvable via
     * CoverageBridge, and at least one active coverage rule configured.
     *
     * Vendor semantics (single-shop master catalog!): a line's candidate
     * vendors are the SUPPLYING shops from the assignment engine — the
     * product's own shop is the master catalog shop, which never carries
     * coverage rules. Enforcement is PER-VENDOR opt-in: a candidate only
     * counts against the line when it has coverage rules configured; vendors
     * without rules fail open (not yet migrated). A line blocks only when it
     * has candidates, every candidate is coverage-configured, and none of
     * them covers the pincode.
     *
     * @param  array<int, array>  $lines  cart lines ({product_id, ...})
     * @return array{blocked: int[], coverage: ?array}
     */
    protected function applyCoverageGate(array $lines, ?string $zip): array
    {
        $none = ['blocked' => [], 'coverage' => null];
        try {
            $settings = Settings::getData();
            if (empty($settings['options']['coverageCheckoutGate'])) {
                return $none;
            }
            if ($zip === null || strlen($zip) < 6) {
                return $none;
            }
            $service = \Marvel\Services\CoverageBridge::service();
            if ($service === null || !$service->anyCoverageConfigured()) {
                return $none;
            }

            $coveredShopIds = array_map('intval', $service->getAvailableNurseryIds($zip));
            $covered = array_flip($coveredShopIds);

            $lineList = collect($lines)
                ->filter(fn ($l) => !empty($l['product_id']))
                ->values();
            if ($lineList->isEmpty()) {
                return $none;
            }

            $assigner = app(\Marvel\Services\ItemAssignmentService::class);

            // Candidate supplying vendors per line via the assignment engine;
            // fall back to the product's own shop when no candidates exist.
            $ids = $lineList->pluck('product_id')->unique()->map(fn ($id) => (int) $id)->values()->all();
            $shopByProduct = \Illuminate\Support\Facades\DB::table('products')
                ->whereIn('id', $ids)->pluck('shop_id', 'id');

            $candidatesByProduct = [];
            $allCandidateShops = [];
            foreach ($lineList as $line) {
                $pid = (int) $line['product_id'];
                $vo = isset($line['variation_option_id']) ? (int) $line['variation_option_id'] : null;
                $qty = max(1, (int) ($line['order_quantity'] ?? $line['quantity'] ?? 1));
                $shops = [];
                try {
                    $cands = $assigner->candidatesFor($pid, $vo, $qty, null, $zip);
                    $shops = collect($cands)->pluck('shop_id')->filter()->map(fn ($s) => (int) $s)->unique()->values()->all();
                } catch (\Throwable $e) {
                    // assignment engine unavailable → fall through to own shop
                }
                if ($shops === [] && isset($shopByProduct[$pid])) {
                    $shops = [(int) $shopByProduct[$pid]];
                }
                $candidatesByProduct[$pid] = $shops;
                foreach ($shops as $s) {
                    $allCandidateShops[$s] = true;
                }
            }

            // Per-vendor opt-in: only vendors WITH active rules are enforced.
            $configured = array_flip(
                \Illuminate\Support\Facades\DB::table('vendor_coverage_rules')
                    ->whereIn('shop_id', array_keys($allCandidateShops))
                    ->where('is_active', 1)
                    ->distinct()->pluck('shop_id')
                    ->map(fn ($s) => (int) $s)->all()
            );

            $blocked = [];
            foreach ($candidatesByProduct as $pid => $shops) {
                if ($shops === []) {
                    continue; // no known vendor — leave to stock/city gates
                }
                $anyPasses = false;
                foreach ($shops as $s) {
                    // Covered, or not coverage-configured (fail open) → passes.
                    if (isset($covered[$s]) || !isset($configured[$s])) {
                        $anyPasses = true;
                        break;
                    }
                }
                if (!$anyPasses) {
                    $blocked[] = $pid;
                }
            }
            if (empty($blocked)) {
                return $none;
            }

            return [
                'blocked'  => $blocked,
                'coverage' => [
                    'pincode'          => $zip,
                    'blocked_products' => $blocked,
                    'reason'           => 'pincode_not_covered',
                    'message'          => "Some items can't be delivered to {$zip}.",
                ],
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('coverage checkout gate failed open', ['error' => $e->getMessage()]);
            return $none;
        }
    }

    /**
     * The Delivery Optimizer's consolidated customer fee — the FEE CUTOVER seam. Returns null
     * unless the optimizer is enabled (flag off ⇒ callers keep the legacy per-product charge,
     * byte-identical) and on ANY failure (fail-open to legacy). The fee is a pure function of
     * amount + settings (same freeShipping threshold keys as legacy), so verify and storeOrder
     * compute the identical value with no quote calls and no shown-vs-charged drift.
     */
    public function optimizerFlatFee(float $amount): ?float
    {
        try {
            $cfg = app(\Marvel\Services\DeliveryOptimizer\Contracts\OptimizerConfigInterface::class);
            if (!$cfg->enabled()) {
                return null;
            }
            // Mirrors DeliveryOptimizerService::customerFee() — same config methods, same
            // >= threshold semantics — so the charged fee always equals the preview's
            // customer_flat_fee, without constructing the full optimizer (quote clients etc.).
            if ($cfg->freeDeliveryEnabled() && $amount >= $cfg->freeDeliveryThreshold()) {
                return 0.0;
            }
            return (float) $cfg->baseFlatFee();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Delivery Optimizer at checkout (FIRM): consolidate the cart into the fewest legs and
     * price them with live carrier quotes. Returns the OptimizationResult array, or null
     * when disabled / on any failure. Metadata only — does NOT change the charged total.
     */
    protected function optimizedCheckout($request, float $amount, bool $isFullWalletPayment): ?array
    {
        if (!app(\Marvel\Services\DeliveryOptimizer\Contracts\OptimizerConfigInterface::class)->enabled()) {
            return null;
        }
        try {
            $ship = (array) ($request['shipping_address'] ?? []);
            $addr = (array) ($ship['address'] ?? $ship);
            $city = $addr['city'] ?? ($ship['city'] ?? null);
            $pincode = $addr['zip'] ?? $addr['pincode'] ?? $addr['postal_code'] ?? ($ship['zip'] ?? null);

            $cartItems = [];
            foreach ((array) $request['products'] as $p) {
                $pid = $p['product_id'] ?? null;
                if (!$pid) {
                    continue;
                }
                $cartItems[] = new \Marvel\Services\DeliveryOptimizer\Dto\CartItem(
                    (int) $pid,
                    isset($p['variation_option_id']) ? (int) $p['variation_option_id'] : null,
                    max(1, (int) ($p['order_quantity'] ?? $p['quantity'] ?? 1)),
                    (int) config('deliveryoptimizer.default_weight_g', 500),
                );
            }
            if (empty($cartItems)) {
                return null;
            }
            $cod = in_array(strtoupper((string) ($request['payment_gateway'] ?? '')), ['CASH_ON_DELIVERY', 'COD', 'CASH'], true);
            $loc = new \Marvel\Services\DeliveryOptimizer\Dto\UserLocation(
                $city !== null ? (string) $city : null,
                $pincode !== null ? (string) $pincode : null,
            );
            $optimizer = app(\Marvel\Services\DeliveryOptimizer\DeliveryOptimizerService::class);
            return $optimizer->optimizeCart($cartItems, $loc, [
                'firm'      => true,
                'cod'       => $cod,
                'codAmount' => $cod ? $amount : 0.0,
                // Authoritative order amount → optimizer free-delivery gate matches line 95.
                'amount'    => $amount,
            ])->toArray();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Customer coordinates from shipping_address.location, if shared. */
    protected function customerLatLng($request): ?array
    {
        $addr = $request['shipping_address'] ?? null;
        $loc  = is_array($addr) ? ($addr['location'] ?? null) : null;
        if (is_array($loc) && isset($loc['lat'], $loc['lng']) && is_numeric($loc['lat']) && is_numeric($loc['lng'])) {
            return ['lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng']];
        }
        return null;
    }

    public function getOrderAmount($request, $unavailable_products)
    {
        if (count($unavailable_products)) {
            return $this->calculateAmountWithAvailable($request['products'], $unavailable_products);
        }
        return  $request['amount'];
    }

    public function calculateTax($request, $shipping_charge, $amount)
    {
        $tax_class = $this->getTaxClass($request);
        if ($tax_class) {
            return $this->getTotalTax($amount, $tax_class);
        }
        return $tax_class;
    }

    public function calculateAmountWithAvailable($products, $unavailable_products)
    {
        $amount = 0;
        foreach ($products as $product) {
            if (!in_array($product['product_id'], $unavailable_products)) {
                $amount += $product['subtotal'];
            }
        }
        return $amount;
    }

    public function calculateShippingCharge($request, $amount)
    {
        try {
            $ordered_products = $request['products'];
            $physical_products = Product::whereIn('id', Arr::pluck($ordered_products, 'product_id'))->where('is_digital', false)->get();
            if (!count($physical_products)) {
                return 0;
            }
            // PlantAtHome charges delivery PER PRODUCT: Σ qty × product.delivery_charge.
            $perProductDelivery = $this->calculatePerProductDelivery($ordered_products);
            if ($perProductDelivery > 0) {
                return $perProductDelivery;
            }
            // Fallback to the legacy shipping-class charge when no product carries a
            // per-product delivery_charge yet (gradual rollout safety).
            $settings = Settings::getData();
            $class_id = $settings['options']['shippingClass'] ?? null;
            if ($class_id) {
                $shipping_class = Shipping::find($class_id);
                return $this->getShippingCharge($shipping_class, $amount);
            } else {
                return $this->calculateShippingChargeByProduct($request['products']);
            }
        } catch (\Throwable $th) {
            return 0;
        }
    }

    /** Σ qty × product.delivery_charge across the cart (one query). */
    protected function calculatePerProductDelivery($products): float
    {
        $ids = Arr::pluck($products, 'product_id');
        $charges = Product::whereIn('id', $ids)->pluck('delivery_charge', 'id');
        $total = 0.0;
        foreach ($products as $product) {
            $qty    = (int) ($product['order_quantity'] ?? 1);
            $charge = (float) ($charges[$product['product_id']] ?? 0);
            $total += $charge * max($qty, 1);
        }
        return round($total, 2);
    }

    protected function calculateShippingChargeByProduct($products)
    {
        $total_charge = 0;
        foreach ($products as $product) {
            $total_charge += $this->calculateEachProductCharge($product['product_id'], $product['subtotal']);
        }
        return $total_charge;
    }

    protected function calculateEachProductCharge($id, $amount)
    {
        $product = Product::with('shipping')->findOrFail($id);
        if (isset($product->shipping)) {
            return $this->getShippingCharge($product->shipping, $amount);
        }
        return 0;
    }

    public function checkStock($products)
    {
        $unavailable_products = [];
        foreach ($products as $product) {
            if (isset($product['variation_option_id'])) {
                $is_not_in_stock = $this->isVariationInStock($product['variation_option_id'], $product['order_quantity']);
            } else {
                $is_not_in_stock = $this->isInStock($product['product_id'], $product['order_quantity']);
            }
            if ($is_not_in_stock) {
                $unavailable_products[] = $is_not_in_stock;
            }
        }
        return $unavailable_products;
    }

    /**
     * City-inventory availability model: per-unit stock is NEVER a blocker. A
     * product (any vertical) that is listed/available in the customer's city is
     * always orderable — vendors fulfil on demand, so nothing is ever "out of
     * stock". The ONLY availability gate is city/vertical, handled by
     * CheckoutRepository::verify() (unavailable_products) and
     * OrderRepository::assertVerticalsAvailable(). Both isInStock and
     * isVariationInStock therefore always report "in stock" so checkStock()
     * returns [] and the 422 "Some items in your cart are out of stock" can
     * never fire for a city-available item.
     */
    protected function isInStock($id, $order_quantity)
    {
        return false; // never out of stock — city is the only gate
    }

    protected function isVariationInStock($variation_id, $order_quantity)
    {
        return false; // never out of stock — city is the only gate
    }

    protected function getShippingCharge($shipping_class, $amount)
    {
        switch ($shipping_class->type) {
            case 'fixed':
                return $shipping_class->amount;
                break;
            case 'percentage':
                return ($shipping_class->amount * $amount) / 100;
                break;
            default:
                return 0;
        }
    }

    protected function getTaxClass($request)
    {
        try {
            $settings = Settings::getData();

            // Get tax settings from settings
            $tax_class = $settings['options']['taxClass'];
            return Tax::findOrFail($tax_class);
        } catch (\Throwable $th) {
            return 0;
        }

        // switch ($tax_type) {
        //     case 'global':
        //         return Tax::where('is_global', '=', true)->first();
        //         break;
        //     case 'billing_address':
        //         $billing_address = $request['billing_address'];
        //         return $this->getTaxClassByAddress($billing_address);
        //         break;
        //     case 'shipping_address':
        //         $shipping_address = $request['shipping_address'];
        //         return $this->getTaxClassByAddress($shipping_address);
        //         break;
        // }
    }

    protected function getTaxClassByAddress($address)
    {
        return Tax::where('country', '=', $address['country'])
            ->orWhere('state', '=', $address['state'])
            ->orWhere('city', '=', $address['city'])
            ->orWhere('zip', '=', $address['zip'])
            ->orderBy('priority', 'asc')
            ->first();
    }

    protected function getTotalTax($amount, $tax_class)
    {
        return ($amount * $tax_class->rate) / 100;
    }
}
