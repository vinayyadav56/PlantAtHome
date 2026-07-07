<?php

namespace Marvel\Services;

use Marvel\Database\Models\Order;

/**
 * Order assignment + fulfillment logic. For each order line, picks the best vendor:
 *   1. a LOCAL vendor — serves the customer's city, in stock → delivery partner (fast)
 *   2. else a COURIER vendor — serves the city by courier, or is out-of-city → courier
 * within a tier the cheapest cost wins. Returns a plan with per-line vendor + mode +
 * ETA; it never mutates the order — the admin approves / overrides on the order screen.
 * "Prioritize fast local fulfillment first, global fulfillment second."
 */
class FulfillmentService
{
    private const DEFAULT_LOCAL_ETA = 2;
    private const DEFAULT_COURIER_ETA = 5;

    public function __construct(private ?AvailabilityService $availability = null)
    {
        $this->availability = $availability ?: new AvailabilityService();
    }

    public function planForOrder(Order $order): array
    {
        $city = $this->orderCity($order);
        $cityN = $this->norm($city);

        $order->loadMissing('products');
        $lines = [];
        $localCount = 0;
        $courierCount = 0;
        $unfilled = 0;

        foreach ($order->products as $product) {
            $qty = (int) ($product->pivot->order_quantity ?? 1);
            $variationOptionId = $product->pivot->variation_option_id ? (int) $product->pivot->variation_option_id : null;
            $vendors = $this->availability->vendorsForProduct((int) $product->id, $variationOptionId);

            $pick = $this->chooseVendor($vendors, $cityN, $qty);
            if ($pick === null) {
                $unfilled++;
            } elseif ($pick['fulfillment_mode'] === 'local') {
                $localCount++;
            } else {
                $courierCount++;
            }

            $lines[] = [
                'product_id'          => (int) $product->id,
                'name'                => $product->name,
                'variation_option_id' => $variationOptionId,
                'quantity'            => $qty,
                'vendor'              => $pick ? [
                    'shop_id'     => $pick['shop_id'],
                    'vendor_name' => $pick['vendor_name'],
                    'cost_price'  => $pick['cost_price'],
                ] : null,
                'fulfillment_mode'    => $pick['fulfillment_mode'] ?? null,
                'eta_days'            => $pick['eta_days'] ?? null,
            ];
        }

        return [
            'order_id'   => $order->id,
            'order_city' => $city,
            'summary'    => [
                'local'    => $localCount,
                'courier'  => $courierCount,
                'unfilled' => $unfilled,
                'eta_days' => $this->planEtaDays($lines),
            ],
            'lines'      => $lines,
        ];
    }

    /**
     * Customer-facing: the best fulfillment for ONE product in a city — { mode, eta_days }
     * (local-in-city first, else courier), or null when no vendor can fulfil it. Used to
     * show delivery timing on the PDP. No vendor/seller identity is returned.
     */
    public function fulfillmentFor(int $productId, ?int $variationOptionId, ?string $city, int $qty = 1): ?array
    {
        $vendors = $this->availability->vendorsForProduct($productId, $variationOptionId);
        $pick = $this->chooseVendor($vendors, $this->norm($city), max(1, $qty));
        if ($pick === null) {
            return null;
        }
        return [
            'fulfillment_mode' => $pick['fulfillment_mode'],
            'eta_days'         => $pick['eta_days'],
        ];
    }

    /** Pick the best vendor for one line: local-in-city first, then courier, cheapest within tier. */
    private function chooseVendor(array $vendors, string $cityN, int $qty): ?array
    {
        // In stock = available + (stock untracked OR enough free units). stock_qty <= 0
        // means the vendor didn't declare stock (price-only sheet) → treat as available.
        $inStock = array_values(array_filter(
            $vendors,
            fn ($v) => !empty($v['is_available'])
                && ((int) ($v['stock_qty'] ?? 0) <= 0 || (int) ($v['available_qty'] ?? 0) >= $qty)
        ));
        if (empty($inStock)) {
            return null;
        }

        // Tier 1: a vendor that serves THIS city locally.
        $local = [];
        $courier = [];
        foreach ($inStock as $v) {
            $localArea = $this->cityArea($v, $cityN, ['local', 'both']);
            if ($localArea) {
                $local[] = [$v, $localArea];
                continue;
            }
            $courierArea = $this->cityArea($v, $cityN, ['courier', 'both']);
            if ($courierArea) {
                $courier[] = [$v, $courierArea];
            }
        }

        if (!empty($local)) {
            [$v, $area] = $this->cheapest($local);
            return $this->decision($v, 'local', $area['eta_days'] ?? self::DEFAULT_LOCAL_ETA);
        }
        if (!empty($courier)) {
            [$v, $area] = $this->cheapest($courier);
            return $this->decision($v, 'courier', $area['eta_days'] ?? self::DEFAULT_COURIER_ETA);
        }
        // Tier 3: in stock but doesn't list this city → ship by courier ONLY from a
        // vendor that can actually courier (a local-only vendor cannot fulfil here).
        $courierCapable = array_values(array_filter($inStock, fn ($v) => $this->canCourier($v)));
        if (empty($courierCapable)) {
            return null;
        }
        $fallback = $this->cheapest(array_map(fn ($v) => [$v, null], $courierCapable));
        return $this->decision($fallback[0], 'courier', self::DEFAULT_COURIER_ETA);
    }

    /** Can this vendor ship by courier (per-row mode or any courier service area)? */
    private function canCourier(array $vendor): bool
    {
        if (in_array($vendor['fulfillment_mode'] ?? '', ['courier', 'both'], true)) {
            return true;
        }
        foreach (($vendor['cities'] ?? []) as $c) {
            if (in_array($c['fulfillment_mode'] ?? '', ['courier', 'both'], true)) {
                return true;
            }
        }
        return false;
    }

    /** The vendor's service-area entry matching the city + an allowed mode (or null). */
    private function cityArea(array $vendor, string $cityN, array $modes): ?array
    {
        foreach (($vendor['cities'] ?? []) as $c) {
            if ($this->norm($c['city'] ?? '') === $cityN && in_array($c['fulfillment_mode'] ?? '', $modes, true)) {
                return $c;
            }
        }
        return null;
    }

    /** Cheapest [vendor, area] pair by vendor RATE (their payout — maximizes platform margin). */
    private function cheapest(array $pairs): array
    {
        usort($pairs, fn ($a, $b) => ($a[0]['vendor_rate'] ?? $a[0]['cost_price'] ?? INF)
            <=> ($b[0]['vendor_rate'] ?? $b[0]['cost_price'] ?? INF));
        return $pairs[0];
    }

    private function decision(array $v, string $mode, $etaDays): array
    {
        return [
            'shop_id'          => $v['shop_id'],
            'vendor_name'      => $v['vendor_name'],
            'cost_price'       => $v['cost_price'],
            'available_qty'    => $v['available_qty'],
            'fulfillment_mode' => $mode,
            'eta_days'         => $etaDays !== null ? (int) $etaDays : null,
        ];
    }

    /** Order-level ETA = the slowest fulfilled line. */
    private function planEtaDays(array $lines): ?int
    {
        $etas = array_filter(array_map(fn ($l) => $l['eta_days'], $lines), fn ($e) => $e !== null);
        return empty($etas) ? null : max($etas);
    }

    private function orderCity(Order $order): ?string
    {
        foreach ([$order->shipping_address, $order->billing_address] as $addr) {
            if (is_array($addr) && isset($addr['city']) && trim((string) $addr['city']) !== '') {
                return trim((string) $addr['city']);
            }
        }
        return null;
    }

    private function norm(?string $s): string
    {
        return strtolower(trim((string) $s));
    }
}
