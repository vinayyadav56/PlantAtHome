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

        // Server-authoritative pricing: reprice vendor-cost-sheet products to their
        // margin-over-cost selling price so the previewed total matches what the
        // order will charge (products without a cost sheet are untouched).
        $request['products'] = (new \Marvel\Services\PricingService())
            ->repriceLines((array) $request['products'], $this->customerLatLng($request));
        $request['amount'] = collect($request['products'])->sum('subtotal');

        $amount = $this->getOrderAmount($request, $unavailable_products);
        $shipping_charge = !empty($settings['options']['freeShipping']) && $settings['options']['freeShippingAmount'] <= $amount ? 0 : $this->calculateShippingCharge($request, $amount);
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
        return [
            'total_tax'            => $tax,
            'shipping_charge'      => $shipping_charge,
            // Delivery Optimizer (additive, flag-gated): FIRM consolidated shipments at
            // checkout. Metadata only for now — `shipping_charge` above is unchanged until
            // an explicit cutover, so the charged total stays byte-identical. Never throws.
            'optimized'            => $this->optimizedCheckout($request, (float) $amount, (bool) ($request['isFullWalletPayment'] ?? false)),
            'unavailable_products' => $unavailable_products,
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

    protected function isInStock($id, $order_quantity)
    {
        try {
            $product = Product::findOrFail($id);
            // A bundle holds no stock of its own — gate against its DERIVED
            // availability (MIN over components) so exhausted bundles surface in
            // unavailable_products and the storefront drops them.
            if ($product->product_type === \Marvel\Enums\ProductType::BUNDLE) {
                $available = app(\Marvel\Services\BundleInventoryService::class)->available($product);
                return $order_quantity > $available ? $id : false;
            }
            if ($order_quantity > $product->quantity) {
                return $id;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    protected function isVariationInStock($variation_id, $order_quantity)
    {
        try {
            $variationOption = Variation::findOrFail($variation_id);
            if ($order_quantity > $variationOption->quantity) {
                return $variationOption->product_id;
            }
            return false;
        } catch (Exception $e) {
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
