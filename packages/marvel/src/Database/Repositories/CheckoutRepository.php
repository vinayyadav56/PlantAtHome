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
        // Whose wallet does the preview show? Same trust rule as order
        // creation: only a SUPER_ADMIN may name another customer. Anyone else
        // (including anonymous callers) sees only their own wallet — an
        // unauthenticated customer_id used to leak any user's balance.
        $effectiveCustomerId = \Marvel\Database\Repositories\OrderRepository::effectiveCustomerId($request);
        if ($effectiveCustomerId) {
            try {
                $user = User::findOrFail($effectiveCustomerId);
            } catch (\Throwable $th) {
                throw new ModelNotFoundException(NOT_FOUND);
            }
        } else {
            $user = $request->user() ?? null;
        }
        $wallet = $user->wallet ?? null;
        $settings = Settings::getData();
        $minimumOrderAmount = isset($settings['options']['minimumOrderAmount']) ? $settings['options']['minimumOrderAmount'] : 0;

        // Lines order-create will refuse (variant missing, foreign, unknown or
        // switched off) are reported LINE by line — unavailable_products is
        // product-level and would also kill the product's valid lines — and
        // left out of everything below, so amount, shipping and tax equal what
        // the order charges once the client drops them.
        $invalid_variant_lines = [];
        try {
            $invalid_variant_lines = $this->variantLineProblems((array) $request['products']);
        } catch (\Throwable $e) {
            // fail open — the order-create guard still refuses cleanly
        }
        if ($invalid_variant_lines) {
            $flagged = array_flip(array_map(fn ($p) => $p['product_id'] . ':' . (int) $p['variation_option_id'], $invalid_variant_lines));
            $request['products'] = array_values(array_filter(
                (array) $request['products'],
                fn ($l) => !isset($flagged[(int) ($l['product_id'] ?? 0) . ':' . (int) ($l['variation_option_id'] ?? 0)])
            ));
        }
        // Old clients read this one: product ids of VARIABLE lines sent without a variant.
        $invalid_option_lines = array_values(array_unique(array_column(
            array_filter($invalid_variant_lines, fn ($p) => $p['reason'] === 'option_missing'),
            'product_id'
        )));

        $unavailable_products = $this->checkStock($request['products']);

        // Operations Control Center — block any cart line whose vertical is
        // currently unavailable. With no shipping city only the platform and global
        // tiers apply, so a coming-soon vertical is refused even when the client sends
        // no address. FAIL OPEN on a fault.
        $blocked_verticals = [];
        $shipCity = is_array($request['shipping_address'] ?? null) ? ($request['shipping_address']['city'] ?? null) : null;
        try {
            $availSvc = app(\Marvel\Services\ServiceAvailabilityService::class);
            $ids = collect($request['products'])->pluck('product_id')->filter()->unique()->values()->all();
            if (!empty($ids)) {
                foreach (Product::with('type')->whereIn('id', $ids)->get(['id', 'type_id']) as $p) {
                    $slug = optional($p->type)->slug;
                    if (!$slug) {
                        continue;
                    }
                    $res = $availSvc->resolve($slug, !empty($shipCity) ? (string) $shipCity : null);
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
        // Display-only policy: shopping city declared but NO nursery supplies it →
        // the cart's city-based lines are unavailable (browse-only city). Structured
        // payload for the storefront's out-of-stock banner. Old clients (no
        // shopping_city) skip. Both gates flag only city-based lines: a single-seller
        // line (Tools) ships anywhere.
        $city_stock = $this->shoppingCityOutOfStock($request);
        if ($city_stock !== null) {
            $unavailable_products = array_values(array_unique(array_merge(
                array_map('intval', (array) $unavailable_products),
                $this->localSupplyProductIds($request)
            )));
        }
        if ($city_mismatch !== null) {
            $unavailable_products = array_values(array_unique(array_merge(
                array_map('intval', $unavailable_products),
                $this->cityBasedProductIds($request)
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
            ->repriceLines((array) $request['products'], $this->customerLatLng($request), $shipCity ? (string) $shipCity : null, catalogFallback: true);
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
        // Admin overrides in the PREVIEW — same super-admin gate as order
        // creation, applied before tax so the preview equals the order.
        $appliedOverrides = [];
        $isSuperAdminCaller = (bool) ($request->user()?->hasPermissionTo(\Marvel\Enums\Permission::SUPER_ADMIN));
        if ($isSuperAdminCaller && $request->filled('delivery_fee_override')) {
            $shipping_charge = round(max(0, (float) $request['delivery_fee_override']), 2);
            $appliedOverrides['delivery_fee'] = $shipping_charge;
        }
        $manualDiscount = 0.0;
        if ($isSuperAdminCaller && $request->filled('manual_discount')) {
            // Clamp to the cart amount — storeOrder clamps the same way, and
            // the preview must equal the order (an over-large discount would
            // otherwise show ₹0 while the order still charges fee+tax).
            $manualDiscount = min(max(0, (float) $request['manual_discount']), (float) $amount);
            $appliedOverrides['manual_discount'] = $manualDiscount;
        }
        $tax = $this->calculateTax($request, $shipping_charge, $amount);
        $total = $amount + $tax + $shipping_charge;
        // Full GST breakdown (superset — the storefront renders CGST/SGST/IGST
        // from these; older clients ignore them and keep using total_tax).
        $gst = $this->gstBreakdown($request, (float) $shipping_charge);
        // Only enforce the minimum-order-amount on a fully-available cart. When
        // some lines are unavailable (out of stock, or a vertical the Operations
        // Control Center has disabled) they're excluded from $amount, which can
        // drop the total below the minimum and surface a misleading "Minimum
        // order amount" error instead of the real "item unavailable" reason. Let
        // the storefront show the unavailable items (returned below) first; the
        // next verify — after the shopper removes them — re-applies this check.
        // Invalid variant lines are excluded the same way, so they count too.
        if (empty($unavailable_products) && empty($invalid_variant_lines) && $total < $minimumOrderAmount) {
            throw new HttpException(400, 'Minimum order amount is ' . $minimumOrderAmount);
        }
        // Products the engine would have to tax at 0% because nothing is configured.
        $tax_unconfigured_products = array_values(array_unique(array_map(
            fn ($l) => (int) $l['product_id'],
            array_filter($gst['lines'] ?? [], fn ($l) => ($l['tax_status'] ?? null) === 'unconfigured')
        )));
        if ($tax_unconfigured_products && (new \Marvel\Services\Tax\BusinessTaxConfig())->blockUnconfiguredAtCheckout()) {
            throw new HttpException(422, 'Some items are missing their tax configuration and cannot be sold yet.');
        }

        $response = [
            'total_tax'            => $tax,
            'taxable_amount'       => $gst['taxable_amount'],
            'cgst_amount'          => $gst['cgst_amount'],
            'sgst_amount'          => $gst['sgst_amount'],
            'igst_amount'          => $gst['igst_amount'],
            'is_inter_state'       => $gst['is_inter_state'],
            'place_of_supply'      => $gst['place_of_supply'],
            'gst_total_tax'        => $gst['total_tax'],
            'delivery_tax_amount'  => $gst['delivery_tax_amount'],
            'shipping_charge'      => $shipping_charge,
            // ── The rest of the breakdown the engine already produced. verify
            // computed all of this and returned none of it, so the storefront
            // could not render a GST-compliant summary without doing tax
            // arithmetic of its own — which is the one thing it must never do.
            'place_of_supply_code'   => $gst['place_of_supply_code'] ?? null,
            'seller_state'           => $gst['seller_state'] ?? null,
            'seller_state_code'      => $gst['seller_state_code'] ?? null,
            'delivery_taxable'       => $gst['delivery_taxable'] ?? 0,
            'delivery_tax_treatment' => $gst['delivery_tax_treatment'] ?? null,
            'prices_include_tax'     => (new \Marvel\Services\Tax\BusinessTaxConfig())->pricesIncludeTax(),
            'tax_calc_version'       => $gst['tax_calc_version'] ?? null,
            // What the customer pays. The server has always computed this and
            // thrown it away, leaving each client to re-add the three parts.
            // manual_discount only ever shifts this when a super-admin sent it
            // (additive-safe for every storefront caller).
            'grand_total'            => round(max(0, (float) $total - $manualDiscount), 2),
            'applied_overrides'      => $appliedOverrides ?: null,
            'delivery_methods'       => $this->deliveryMethods($request, (array) $unavailable_products),
            'tax_unconfigured_products' => $tax_unconfigured_products,
            // Delivery Optimizer (additive, flag-gated): FIRM consolidated shipments at
            // checkout. Metadata only for now — `shipping_charge` above is unchanged until
            // an explicit cutover, so the charged total stays byte-identical. Never throws.
            'optimized'            => $this->optimizedCheckout($request, (float) $amount, (bool) ($request['isFullWalletPayment'] ?? false)),
            'unavailable_products' => $unavailable_products,
            // Line-level: VARIABLE products whose cart line has no
            // variation_option_id (ghost lines from old carts). Clients drop
            // exactly the option-less line — the product's valid variation
            // lines stay orderable.
            'invalid_option_lines' => $invalid_option_lines,
            // Every line order-create would refuse, with why ({product_id,
            // variation_option_id, reason}); none of them is in the totals.
            'invalid_variant_lines' => $invalid_variant_lines,
            'city_stock'           => $city_stock,
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
            // Per-line GST + delivery, for the checkout breakdown and the invoice
            // preview. Same shape that gets frozen onto order_items.
            'lines'                => $this->verifyLines((array) $request['products'], $gst['lines'] ?? []),
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

    /**
     * Cart lines merged with their tax, keyed the same way the order snapshot is.
     *
     * @param  array $products cart lines as repriced by the server
     * @param  array $taxLines GstService per-line output
     */
    protected function verifyLines(array $products, array $taxLines): array
    {
        $key = fn ($pid, $vid) => ((int) $pid) . ':' . ($vid ? (int) $vid : 0);
        $byKey = [];
        foreach ($taxLines as $line) {
            $byKey[$key($line['product_id'] ?? 0, $line['variation_option_id'] ?? null)] = $line;
        }

        $out = [];
        foreach ($products as $product) {
            $tax = $byKey[$key($product['product_id'] ?? 0, $product['variation_option_id'] ?? null)] ?? [];
            $out[] = [
                'product_id'          => isset($product['product_id']) ? (int) $product['product_id'] : null,
                'variation_option_id' => isset($product['variation_option_id']) && $product['variation_option_id']
                    ? (int) $product['variation_option_id']
                    : null,
                'order_quantity'      => (int) ($product['order_quantity'] ?? 1),
                'unit_price'          => isset($product['unit_price']) ? (float) $product['unit_price'] : null,
                'subtotal'            => isset($product['subtotal']) ? (float) $product['subtotal'] : null,
                'hsn_code'            => $tax['hsn_code'] ?? null,
                'tax_category'        => $tax['tax_category'] ?? null,
                'tax_rate'            => $tax['tax_rate'] ?? 0,
                'tax_inclusive'       => $tax['tax_inclusive'] ?? null,
                'taxable_value'       => $tax['taxable_value'] ?? null,
                'cgst_rate'           => $tax['cgst_rate'] ?? 0,
                'sgst_rate'           => $tax['sgst_rate'] ?? 0,
                'igst_rate'           => $tax['igst_rate'] ?? 0,
                'cgst_amount'         => $tax['cgst_amount'] ?? 0,
                'sgst_amount'         => $tax['sgst_amount'] ?? 0,
                'igst_amount'         => $tax['igst_amount'] ?? 0,
                'tax_amount'          => $tax['tax_amount'] ?? 0,
                'delivery_fee'        => $tax['delivery_fee'] ?? 0,
                'tax_status'          => $tax['tax_status'] ?? 'configured',
            ];
        }

        return $out;
    }

    /** Shipping pincode from the verify payload (zip | pincode | postal_code, possibly nested under address). */
    /**
     * Delivery methods available for THIS cart+address, derived from data that
     * already exists — no hardcoded state names. Local = cart fully available
     * with a known ship city; Standard Interstate = any supplying vendor's
     * origin state differs from the destination state (origin resolution:
     * shop address state → default pickup location state → the business
     * registration state). Recorded intent only — fulfillment lanes are still
     * chosen at assignment. Returns null (key omitted) when underivable.
     */
    public function deliveryMethods($request, array $unavailableProducts): ?array
    {
        try {
            $zip = $this->shippingZip($request);
            $ids = collect($request['products'] ?? [])->pluck('product_id')
                ->filter()->map(fn ($i) => (int) $i)->unique()->values()->all();
            if (!$zip || strlen($zip) !== 6 || empty($ids)) {
                return null;
            }
            $dest = \Illuminate\Support\Facades\DB::table('postal_codes')
                ->leftJoin('states', 'states.id', '=', 'postal_codes.state_id')
                ->leftJoin('cities', 'cities.id', '=', 'postal_codes.city_id')
                ->leftJoin('districts', 'districts.id', '=', 'postal_codes.district_id')
                ->where('postal_codes.pincode', $zip)
                ->first(['states.name as state_name', 'cities.name as city_name', 'districts.name as district_name']);
            if (!$dest || empty($dest->state_name)) {
                return null;
            }
            $norm = fn ($v) => mb_strtolower(trim((string) $v));
            $destState = $norm($dest->state_name);

            // Origin states of every supplying vendor for these products.
            $shopIds = [];
            foreach ($this->supplyingShops($ids) as $perProduct) {
                foreach ((array) $perProduct as $sid) {
                    $shopIds[(int) $sid] = true;
                }
            }
            // Global catalogue: a line no nursery in the delivery city supplies locally ships by
            // courier from the fallback shop — that shop is an origin, and the line is not local.
            $localLines = $this->cityBasedProductIds($request);
            $svc = app(\Marvel\Services\ServiceAvailabilityService::class);
            $global = array_filter($localLines, fn ($pid) => $svc->globalFallbackFor($pid) !== null);
            if ($global) {
                $shipCity = is_array($request['shipping_address'] ?? null) ? ($request['shipping_address']['city'] ?? null) : null;
                $here = $shipCity ?: ($dest->city_name ?: $dest->district_name);
                $localHere = $here
                    ? array_flip(array_map('intval', (new \Marvel\Services\AvailabilityService())->availableProductIdsInCity((string) $here, true)))
                    : [];
                foreach ($global as $pid) {
                    if (!isset($localHere[$pid])) {
                        $shopIds[(int) $svc->globalFallbackFor($pid)] = true;
                        $localLines = array_values(array_diff($localLines, [$pid]));
                    }
                }
            }
            $originStates = [];
            if ($shopIds) {
                foreach (\Marvel\Database\Models\Shop::whereIn('id', array_keys($shopIds))->get(['id', 'address']) as $shop) {
                    $state = $norm(data_get($shop->address, 'state'));
                    if ($state === '') {
                        $pickup = \Illuminate\Support\Facades\DB::table('vendor_pickup_locations')
                            ->where('shop_id', $shop->id)
                            ->orderByDesc('is_default')
                            ->value('state');
                        $state = $norm($pickup);
                    }
                    if ($state !== '') {
                        $originStates[$state] = true;
                    }
                }
            }
            if (!$originStates) {
                // Single-origin business fallback: the GST registration state.
                $reg = $norm(app(\Marvel\Services\Tax\BusinessTaxConfig::class)->registrationState());
                if ($reg !== '') {
                    $originStates[$reg] = true;
                }
            }
            if (!$originStates) {
                return null;
            }

            $anyInterstate = (bool) array_filter(array_keys($originStates), fn ($s) => $s !== $destState);
            $shipCityKnown = !empty($dest->city_name) || !empty($dest->district_name);
            // A Tools-only cart ships by courier from its seller — never "local"; nor does a
            // global-catalogue line no nursery here supplies.
            $localAvailable = empty($unavailableProducts) && $shipCityKnown && $localLines;

            return [
                [
                    'slug' => 'local',
                    'label' => 'Local Delivery',
                    'available' => $localAvailable,
                    'eta_text' => $localAvailable ? 'Delivered locally' : null,
                    'reason' => $localAvailable ? null : 'no_local_supply',
                ],
                [
                    'slug' => 'standard_interstate',
                    'label' => 'Standard Interstate',
                    'available' => $anyInterstate || !$localAvailable,
                    'eta_text' => 'Usually 3–7 days',
                    'reason' => ($anyInterstate || !$localAvailable) ? null : 'same_state_origin',
                ],
            ];
        } catch (\Throwable $e) {
            return null; // additive key — never fail verify over it
        }
    }

    public function shippingZip($request): ?string
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
    /**
     * Display-only policy: when the client declares a shopping city that is
     * serviceable but has NO nursery supply, ordering is blocked (browse-only).
     * Null when not applicable (no shopping_city sent — old clients — or the
     * city has supply). Fail-open on any fault.
     */
    public function shoppingCityOutOfStock($request): ?array
    {
        try {
            $city = trim((string) ($request['shopping_city'] ?? ''));
            if ($city === '') {
                return null;
            }
            // A cart of single-seller (Tools) or global-catalogue (fallback) lines only never
            // depends on the city's vendor supply.
            $lines = collect($request['products'] ?? [])->pluck('product_id')->filter()->unique();
            $cityLines = $this->localSupplyProductIds($request);
            if ($lines->isNotEmpty() && !$cityLines) {
                return null;
            }
            $availability = app(\Marvel\Services\AvailabilityService::class);
            if ($availability->cityHasSupply($city)) {
                return null;
            }
            return [
                'code'    => 'CITY_OUT_OF_STOCK',
                'city'    => $city,
                'message' => count($cityLines) < $lines->count()
                    ? sprintf("Plants aren't available in %s yet. Remove them to order the rest of your cart.", $city)
                    : sprintf(
                        'All products are currently out of stock in %s. You can browse the catalog, but orders cannot be placed in this city yet.',
                        $city
                    ),
            ];
        } catch (\Throwable $e) {
            return null; // never break checkout on a gate fault
        }
    }

    public function shoppingCityMismatch($request): ?array
    {
        try {
            $shoppingCity = trim((string) ($request['shopping_city'] ?? ''));
            if ($shoppingCity === '') {
                return null; // gate applies only when the client declares a shopping city
            }
            if (!empty($request['products'] ?? null) && !$this->cityBasedProductIds($request)) {
                return null; // single-seller lines only (Tools): any address in the country
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
     * Product ids of the cart lines that depend on the shopping city: all of them except
     * single-seller products (Tools), which ship nationwide from their seller.
     *
     * @return int[]
     */
    public function cityBasedProductIds($request): array
    {
        $svc = app(\Marvel\Services\ServiceAvailabilityService::class);
        return collect($request['products'] ?? [])->pluck('product_id')->filter()
            ->map(fn ($i) => (int) $i)->unique()
            ->reject(fn ($pid) => $svc->singleSellerFor($pid) !== null)
            ->values()->all();
    }

    /**
     * The city-based lines that need a vendor IN the shopping city: all of them except
     * global-catalogue lines, which the fallback shop fulfils wherever no vendor does. The
     * browse-only (CITY_OUT_OF_STOCK) gate asks this; the city-MISMATCH gate keeps asking
     * cityBasedProductIds(), so the price shown stays the price charged.
     *
     * @return int[]
     */
    public function localSupplyProductIds($request): array
    {
        $svc = app(\Marvel\Services\ServiceAvailabilityService::class);
        return array_values(array_filter(
            $this->cityBasedProductIds($request),
            fn ($pid) => $svc->globalFallbackFor($pid) === null
        ));
    }

    /**
     * Delivery Coverage checkout gate — the ONE pincode gate, shared by
     * verify() and OrderRepository::storeOrder() so a client that skips verify
     * cannot slip past it. Guard chain, each miss failing open silently: the
     * `coverageCheckoutGate` setting, a >= 6-digit shipping pincode, and a
     * resolvable coverage module.
     *
     * Vendor semantics (single-shop master catalog!): a line's vendors are the
     * SUPPLYING shops in vendor_product_prices — the product's own shop is the
     * master catalog shop, which never carries coverage rules, so there is
     * deliberately no fall back to it. Enforcement is PER-VENDOR, PER-VERTICAL
     * opt-in: a vendor is only enforced for a line once it has rules that could
     * answer that line's vertical ('*' or the vertical itself). A line blocks
     * only when it has supplying vendors, every one of them is configured for
     * the vertical, and none of them covers the pincode.
     *
     * Asks VendorServiceabilityResolver, not the assignment engine: the engine
     * consults this same resolver, so calling it here would be circular.
     *
     * Global-catalogue lines (the fallback shop fulfils them where no vendor does) first
     * need a real Indian PIN — the postal master — whatever the coverage setting:
     * PINCODE_UNKNOWN. A missing PIN only fails when $requirePin (order creation; the cart
     * page's delivery preview verifies without an address). Then, with the gate on, such a
     * line passes when a covering vendor OR the fallback's own rules for its vertical cover it.
     *
     * @param  array<int, array>  $lines  cart lines ({product_id, ...})
     * @return array{blocked: int[], coverage: ?array}
     */
    public function applyCoverageGate(array $lines, ?string $zip, bool $requirePin = false): array
    {
        $none = ['blocked' => [], 'coverage' => null];
        try {
            $ids = collect($lines)->pluck('product_id')->filter()
                ->map(fn ($id) => (int) $id)->unique()->values()->all();
            if ($ids === []) {
                return $none;
            }

            $svc = app(\Marvel\Services\ServiceAvailabilityService::class);
            $fallbacks = [];
            foreach ($ids as $pid) {
                if (($shop = $svc->globalFallbackFor($pid)) !== null) {
                    $fallbacks[$pid] = $shop;
                }
            }
            if ($fallbacks && ($zip !== null || $requirePin)
                && \Marvel\Services\CoverageBridge::isKnownPincode($zip) === false) {
                $blocked = array_keys($fallbacks);
                return [
                    'blocked'  => $blocked,
                    'coverage' => [
                        'code'             => 'PINCODE_UNKNOWN',
                        'pincode'          => $zip,
                        'blocked_products' => $blocked,
                        'reason'           => 'unknown_pincode',
                        'message'          => "We couldn't find this PIN code. Please check it and try again.",
                    ],
                ];
            }

            $settings = Settings::getData();
            if (empty($settings['options']['coverageCheckoutGate'])) {
                return $none;
            }
            if ($zip === null || strlen($zip) < 6) {
                return $none;
            }

            $verticalByProduct = $this->productVerticals($ids);
            $supplyByProduct = $this->supplyingShops($ids);
            $allShops = collect($supplyByProduct)->flatten()->unique()->values()->all();
            if ($allShops === [] && $fallbacks === []) {
                return $none;
            }

            // One narrowing, shared with the PDP, the price ETA and the
            // assignment engine — CoverageBridge::allowedShops also carries the
            // per-vendor opt-in, so an unmigrated vendor still passes.
            $allowedByVertical = [];
            $blocked = [];
            foreach ($supplyByProduct as $pid => $shops) {
                $vertical = $verticalByProduct[$pid] ?? '*';
                if (!array_key_exists($vertical, $allowedByVertical)) {
                    $allowedByVertical[$vertical] = \Marvel\Services\CoverageBridge::allowedShops($allShops, $zip, $vertical);
                }
                $allowed = $allowedByVertical[$vertical];
                if ($allowed === null) {
                    return $none; // narrowing is off or unavailable → fail open
                }

                $passing = array_filter($shops, fn ($shopId) => isset($allowed[$shopId]));
                if ($passing === [] && !isset($fallbacks[$pid])) {
                    $blocked[] = $pid;
                }
            }
            // A global-catalogue line whose vendors (if any) don't cover the pin falls back.
            foreach ($fallbacks as $pid => $shop) {
                $vendorPasses = isset($supplyByProduct[$pid], $allowedByVertical[$verticalByProduct[$pid] ?? '*'])
                    && array_filter($supplyByProduct[$pid], fn ($s) => isset($allowedByVertical[$verticalByProduct[$pid] ?? '*'][$s]));
                if (!$vendorPasses
                    && !\Marvel\Services\CoverageBridge::fallbackCovers($shop, $zip, $verticalByProduct[$pid] ?? null)) {
                    $blocked[] = $pid;
                }
            }
            if (empty($blocked)) {
                return $none;
            }

            return [
                'blocked'  => $blocked,
                'coverage' => [
                    'code'             => 'PINCODE_NOT_COVERED',
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
     * The blocking form of the gate, for order creation: same verdict verify()
     * shows advisorily, thrown as the structured 422 the clients switch on.
     * Lives here so verify() and storeOrder() cannot drift apart — there is one
     * gate and one payload, and this is the only place that turns it into an
     * exception.
     */
    public function assertCoverage($request): void
    {
        // requirePin: an order for a global-catalogue line must carry its delivery PIN.
        $gate = $this->applyCoverageGate((array) ($request['products'] ?? []), $this->shippingZip($request), true);
        if ($gate['coverage'] === null) {
            return;
        }

        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json($gate['coverage'], 422)
        );
    }

    /**
     * Cart lines that can never become order lines, with why:
     *   option_missing  a VARIABLE product sent without its variant
     *   not_found       the variant does not exist, or is another product's
     *   not_variable    a variant sent for a product that has none
     *   disabled        the variant is switched off
     * Every server-side price, delivery charge and stock decrement keys on the
     * line's variation_option_id, so a foreign one used to bring its own price.
     * One entry per distinct line. Query builder: two light queries, no models.
     *
     * @param  array<int, array> $lines cart lines ({product_id, variation_option_id?})
     * @return array<int, array{product_id: int, variation_option_id: ?int, reason: string}>
     */
    public function variantLineProblems(array $lines): array
    {
        $pids = array_values(array_unique(array_filter(array_map(fn ($l) => (int) ($l['product_id'] ?? 0), $lines))));
        $vids = array_values(array_unique(array_filter(array_map(fn ($l) => (int) ($l['variation_option_id'] ?? 0), $lines))));
        $types = $pids ? \Illuminate\Support\Facades\DB::table('products')->whereIn('id', $pids)->pluck('product_type', 'id')->all() : [];
        $variants = $vids
            ? \Illuminate\Support\Facades\DB::table('variation_options')->whereIn('id', $vids)->get(['id', 'product_id', 'is_disable'])->keyBy('id')->all()
            : [];

        $out = [];
        foreach ($lines as $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            $vid = (int) ($line['variation_option_id'] ?? 0);
            $variable = strtolower((string) ($types[$pid] ?? '')) === \Marvel\Enums\ProductType::VARIABLE;
            $variant = $variants[$vid] ?? null;
            $reason = match (true) {
                !$vid => $variable ? 'option_missing' : null,
                !$variant || (int) $variant->product_id !== $pid => 'not_found',
                !$variable => 'not_variable',
                (bool) $variant->is_disable => 'disabled',
                default => null,
            };
            if ($reason !== null) {
                $out[$pid . ':' . $vid] = ['product_id' => $pid, 'variation_option_id' => $vid ?: null, 'reason' => $reason];
            }
        }

        return array_values($out);
    }

    /**
     * The blocking form, for order creation: refuse the cart before anything is
     * priced, naming the products, with the lines for clients to drop. Fails
     * closed — verify() is the advisory side.
     */
    public function assertVariantLines($request): void
    {
        $problems = $this->variantLineProblems((array) ($request['products'] ?? []));
        if (!$problems) {
            return;
        }
        $names = \Illuminate\Support\Facades\DB::table('products')
            ->whereIn('id', array_unique(array_column($problems, 'product_id')))
            ->pluck('name')->filter()->all();

        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
            'code'    => 'INVALID_VARIANT',
            'message' => 'The option you picked for ' . (Arr::join($names, ', ', ' and ') ?: 'an item in your cart')
                . ' is no longer available — remove it from your cart and add it again.',
            'lines'   => $problems,
        ], 422));
    }

    /**
     * Vertical (Type slug) per product — the scope a vendor's rules are matched
     * against. '*' whenever the catalogue cannot say.
     *
     * @param  int[]  $ids
     * @return array<int,string>
     */
    private function productVerticals(array $ids): array
    {
        $schema = \Illuminate\Support\Facades\DB::getSchemaBuilder();
        if (!$schema->hasColumn('products', 'type_id') || !$schema->hasTable('types')) {
            return [];
        }

        return \Illuminate\Support\Facades\DB::table('products')
            ->leftJoin('types', 'types.id', '=', 'products.type_id')
            ->whereIn('products.id', $ids)
            ->pluck('types.slug', 'products.id')
            ->filter()->map(fn ($slug) => (string) $slug)->all();
    }

    /**
     * Supplying vendors per product: live vendor_product_prices rows (available,
     * inside their effective window, vendor active and not on hold). Same supply
     * definition AvailabilityService projects city availability from, so the gate
     * cannot disagree with what the customer was shown.
     *
     * @param  int[]  $ids
     * @return array<int, int[]>
     */
    private function supplyingShops(array $ids): array
    {
        $db = \Illuminate\Support\Facades\DB::table('vendor_product_prices')
            ->join('shops', 'shops.id', '=', 'vendor_product_prices.shop_id')
            ->whereIn('vendor_product_prices.product_id', $ids)
            ->where('vendor_product_prices.is_available', 1)
            ->where('shops.is_active', 1);

        $schema = \Illuminate\Support\Facades\DB::getSchemaBuilder();
        if ($schema->hasColumn('vendor_product_prices', 'deleted_at')) {
            $db->whereNull('vendor_product_prices.deleted_at');
        }
        if ($schema->hasColumn('vendor_product_prices', 'review_status')) {
            $db->where('vendor_product_prices.review_status', 'approved');
        }
        if ($schema->hasColumn('shops', 'approval_status')) {
            $db->where(fn ($q) => $q->whereNull('shops.approval_status')
                ->orWhere('shops.approval_status', '!=', \Marvel\Database\Models\Shop::STATUS_ON_HOLD));
        }
        $today = \Carbon\Carbon::today()->toDateString();
        $db->where(fn ($q) => $q->whereNull('vendor_product_prices.effective_from')->orWhere('vendor_product_prices.effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('vendor_product_prices.effective_to')->orWhere('vendor_product_prices.effective_to', '>=', $today));

        $out = [];
        foreach ($db->get(['vendor_product_prices.product_id', 'vendor_product_prices.shop_id']) as $row) {
            $out[(int) $row->product_id][(int) $row->shop_id] = true;
        }
        // A single-seller product (Tools) is supplied by its seller only; a product left
        // with no supplier drops out, exactly as if it had no rows.
        $svc = app(\Marvel\Services\ServiceAvailabilityService::class);
        foreach ($out as $pid => $shops) {
            $seller = $svc->singleSellerFor($pid);
            if ($seller !== null) {
                $out[$pid] = array_intersect_key($shops, [$seller => true]);
            }
        }

        return array_map('array_keys', array_filter($out));
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

    /**
     * The on-top tax to ADD to the payable total. Delegates to the one GST engine
     * so the verify preview and the order charge use identical math. Returns 0
     * when prices are tax-inclusive (the default) — GST is embedded in the price
     * the customer already sees, so the total is unchanged; the CGST/SGST/IGST
     * split is persisted separately as the order tax snapshot (see gstBreakdown).
     *
     * Legacy fallback: if no product-level GST is configured anywhere but a flat
     * settings.options.taxClass exists, honour it (backward compatibility).
     */
    public function calculateTax($request, $shipping_charge, $amount)
    {
        $products = $this->withLineDelivery($request['products'] ?? []);
        $shippingAddress = $request['shipping_address'] ?? ($request['billing_address'] ?? null);
        $addon = (new \Marvel\Services\Tax\GstService())->taxAddon($products, (float) $shipping_charge, is_array($shippingAddress) ? $shippingAddress : null);
        if ($addon > 0) {
            return $addon;
        }
        // No product-level tax produced an add-on. Preserve the legacy flat tax
        // class if one is configured (so existing exclusive setups keep charging).
        $tax_class = $this->getTaxClass($request);
        if ($tax_class) {
            return $this->getTotalTax($amount, $tax_class);
        }
        return 0;
    }

    /** Full GST breakdown for snapshot/preview. Single source: the GST engine. */
    public function gstBreakdown($request, $shipping_charge): array
    {
        $products = $this->withLineDelivery($request['products'] ?? []);
        $shippingAddress = $request['shipping_address'] ?? ($request['billing_address'] ?? null);
        return (new \Marvel\Services\Tax\GstService())->compute($products, (float) $shipping_charge, is_array($shippingAddress) ? $shippingAddress : null);
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
            // PlantAtHome charges delivery by SIZE: Σ qty × the variant's charge.
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

    /** Σ of the per-line delivery charges across the cart. */
    protected function calculatePerProductDelivery($products): float
    {
        return round(array_sum($this->perLineDelivery($products)), 2);
    }

    /**
     * What each line costs to deliver, keyed "productId:variationOptionId".
     *
     * The customer pays by SIZE — Small, Medium and Large each carry their own
     * charge in the variant master — falling back to the product's own flat
     * charge for anything with no size (simple products, non-size variants).
     * Per UNIT, exactly like the per-product charge this replaces: a plant ships
     * as its own parcel.
     *
     * A resolver fault degrades to that per-product charge rather than
     * propagating: calculateShippingCharge catches everything and returns 0, so
     * an exception here would quietly hand out free delivery on every order.
     */
    public function perLineDelivery($products): array
    {
        $products = (array) $products;
        $ids = Arr::pluck($products, 'product_id');
        $productCharges = Product::whereIn('id', $ids)->pluck('delivery_charge', 'id');

        $sizes = [];
        try {
            $sizes = (new \Marvel\Services\VariantResolver())->mapForLines($products);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('variant delivery lookup failed; using the per-product charge', ['error' => $e->getMessage()]);
        }

        $out = [];
        foreach ($products as $product) {
            $pid = (int) ($product['product_id'] ?? 0);
            $vid = (int) ($product['variation_option_id'] ?? 0);
            $qty = max((int) ($product['order_quantity'] ?? 1), 1);

            $perUnit = $sizes[$pid . ':' . $vid]->delivery_charge ?? null;
            if ($perUnit === null) {
                $perUnit = $productCharges[$pid] ?? 0;
            }
            $out[$pid . ':' . $vid] = round(((float) $perUnit) * $qty, 2);
        }

        return $out;
    }

    /**
     * Cart lines carrying their own delivery charge.
     *
     * The GST engine uses these only as WEIGHTS — freight is taxed at the
     * quantity-weighted average of the goods' rates, then applied to whatever
     * delivery actually costs — so a free-shipping coupon or the optimizer fee
     * replacing the total cannot skew the split. Without them a mixed-rate cart
     * weighted freight by goods value, which is not what the customer is paying
     * per parcel.
     */
    public function withLineDelivery($products): array
    {
        $products = (array) $products;
        try {
            $map = $this->perLineDelivery($products);
        } catch (\Throwable $e) {
            return $products;
        }
        foreach ($products as &$product) {
            $key = ((int) ($product['product_id'] ?? 0)) . ':' . ((int) ($product['variation_option_id'] ?? 0));
            $product['delivery_fee'] = $map[$key] ?? 0.0;
        }
        unset($product);

        return $products;
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
                $is_not_in_stock = $this->isVariationInStock($product['variation_option_id'], $product['order_quantity'], $product['product_id'] ?? null);
            } else {
                $is_not_in_stock = $this->isInStock($product['product_id'], $product['order_quantity']);
            }
            if (!$is_not_in_stock) {
                $is_not_in_stock = $this->globalNotOrderable((int) ($product['product_id'] ?? 0));
            }
            if ($is_not_in_stock) {
                $unavailable_products[] = $is_not_in_stock;
            }
        }
        return $unavailable_products;
    }

    /**
     * A global-catalogue line is sold with no vendor in the loop, so the catalogue rules are
     * its only gate: the product id when it is not orderable (draft, unlisted, unpriced —
     * Product::orderable()), else false. Fail-open, like singleSellerOutOfStock.
     */
    private function globalNotOrderable(int $productId)
    {
        try {
            if ($productId <= 0 || app(\Marvel\Services\ServiceAvailabilityService::class)->globalFallbackFor($productId) === null) {
                return false;
            }
            return Product::whereKey($productId)->orderable()->exists() ? false : $productId;
        } catch (\Throwable $e) {
            return false;
        }
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
     *
     * The one exception is a single-seller product (Tools): no city gate ever applies to
     * it, so it is out of stock unless its seller can fill the line.
     */
    protected function isInStock($id, $order_quantity)
    {
        return $this->singleSellerOutOfStock((int) $id, null, (int) $order_quantity);
    }

    protected function isVariationInStock($variation_id, $order_quantity, $product_id = null)
    {
        return $product_id
            ? $this->singleSellerOutOfStock((int) $product_id, (int) $variation_id, (int) $order_quantity)
            : false;
    }

    /** The product id when a single-seller product's seller can't fill the line, else false. Fail-open. */
    private function singleSellerOutOfStock(int $productId, ?int $variationOptionId, int $qty)
    {
        try {
            if (app(\Marvel\Services\ServiceAvailabilityService::class)->singleSellerFor($productId) === null) {
                return false; // city is the only gate
            }
            return (new \Marvel\Services\ItemAssignmentService())->bestFor($productId, $variationOptionId, max(1, $qty), null) === null
                ? $productId
                : false;
        } catch (\Throwable $e) {
            return false;
        }
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

            // Get tax settings from settings. Null-safe: with no settings row (a fresh box) or
            // no taxClass configured, `$settings['options']` is null and indexing it emitted
            // "Trying to access array offset on value of type null" on EVERY checkout verify —
            // noise in exactly the log an operator reads to find out why a verify failed.
            $tax_class = data_get($settings, 'options.taxClass');
            if (!$tax_class) {
                return 0;
            }
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
