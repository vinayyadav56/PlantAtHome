<?php

namespace Marvel\Services;

use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\VendorServiceArea;
use Marvel\Database\Models\VendorShippingRate;

/**
 * Per-item vendor assignment scoring (the heart of P3). For one master product + size +
 * quantity in a customer's city/pincode, it ranks the vendors that can fulfil it, in the
 * priority order: 1 inventory, 2 pincode coverage, 3 delivery SLA, 4 vendor rating,
 * 5 vendor priority, 6 shipping cost, then 7 admin override (handled at the controller).
 *
 * Hard filters (a candidate must pass): in stock for the quantity, and serves the city
 * (locally, by courier, or courier-capable nationally). LOCAL always outranks COURIER
 * ("fast local first"); within a tier the weighted score decides. The customer never
 * sees any of this — it drives admin assignment + the (hidden) checkout estimate.
 */
class ItemAssignmentService
{
    private const DEFAULT_LOCAL_ETA = 2;
    private const DEFAULT_COURIER_ETA = 5;

    private array $weights;
    private int $targetSla;

    public function __construct(private ?AvailabilityService $availability = null)
    {
        $this->availability = $availability ?: new AvailabilityService();
        $settings = Settings::getData(); // may be null on a blank install
        $opts = (array) (($settings?->options['assignment'] ?? []) ?: []);
        $this->weights = [
            'inv'      => (float) ($opts['w_inv'] ?? 0.10),
            'pincode'  => (float) ($opts['w_pincode'] ?? 0.15),
            'sla'      => (float) ($opts['w_sla'] ?? 0.20),
            'rating'   => (float) ($opts['w_rating'] ?? 0.15),
            'priority' => (float) ($opts['w_priority'] ?? 0.15),
            'shipping' => (float) ($opts['w_shipping'] ?? 0.25),
        ];
        $this->targetSla = max(1, (int) ($opts['target_sla_days'] ?? 3));
    }

    /**
     * Ranked vendor candidates for one line. Returns [] when nobody can fulfil it.
     * Each candidate: shop_id, vendor_name, selling_price, available_qty, fulfillment_mode,
     * pincode_covered, sla_days, eta_days, rating, priority, shipping_cost, score, recommended.
     */
    public function candidatesFor(int $productId, ?int $variationOptionId, int $qty, ?string $city, ?string $pincode = null): array
    {
        $qty = max(1, $qty);
        $cityN = $this->norm($city);
        $vendors = $this->availability->vendorsForProduct($productId, $variationOptionId);
        if (empty($vendors)) {
            return [];
        }

        $shopIds = array_values(array_unique(array_map(fn ($v) => (int) $v['shop_id'], $vendors)));
        $shops = Shop::whereIn('id', $shopIds)->get()->keyBy('id');
        $areas = VendorServiceArea::whereIn('shop_id', $shopIds)->where('is_active', true)->get()->groupBy('shop_id');
        $rates = VendorShippingRate::whereIn('shop_id', $shopIds)->where('is_active', true)->get()->groupBy('shop_id');

        $candidates = [];
        foreach ($vendors as $v) {
            $shopId = (int) $v['shop_id'];

            // Hard filter 1 — inventory.
            $stockQty = (int) ($v['stock_qty'] ?? 0);
            $availQty = (int) ($v['available_qty'] ?? 0);
            $inStock = !empty($v['is_available']) && ($stockQty <= 0 || $availQty >= $qty);
            if (!$inStock) {
                continue;
            }

            // Hard filter 2 — serves the city (local first, then courier, then national courier).
            $area = $this->matchArea($areas[$shopId] ?? collect(), $cityN, $pincode);
            if ($area['mode'] === null) {
                continue; // cannot reach this customer
            }
            $mode = $area['mode']; // 'local' | 'courier'

            $shop = $shops[$shopId] ?? null;
            $rating = $shop && $shop->vendor_rating !== null ? (float) $shop->vendor_rating : null;
            $priority = $shop ? (int) ($shop->vendor_priority_score ?? 50) : 50;

            // Treat a stored 0 (or null) eta as "unset" so it falls through to the SLA
            // default — a 0-day window would both inflate the score and over-promise.
            $slaDays = ($area['eta_days'] ?: null)
                ?? ($shop && $shop->sla_default_days ? (int) $shop->sla_default_days : null)
                ?? ($mode === 'local' ? self::DEFAULT_LOCAL_ETA : self::DEFAULT_COURIER_ETA);

            $shippingCost = $this->shippingCost($rates[$shopId] ?? collect(), $mode, $area['pincode_covered']);

            $candidates[] = [
                'shop_id'                 => $shopId,
                'vendor_product_price_id' => $v['vendor_product_price_id'] ?? null,
                'vendor_name'             => $v['vendor_name'] ?? null,
                'selling_price'           => $v['selling_price'] ?? null,
                'available_qty'    => $availQty,
                'stock_tracked'    => $stockQty > 0,
                'fulfillment_mode' => $mode,
                'serves_city'      => true,
                'pincode_covered'  => $area['pincode_covered'],
                'sla_days'         => (int) $slaDays,
                'eta_days'         => (int) $slaDays,
                'rating'           => $rating,
                'priority'         => $priority,
                'shipping_cost'    => $shippingCost,
                // raw score parts filled below after we know the max shipping cost
                '_inv'             => $stockQty <= 0 ? 0.5 : $this->clamp($availQty / ($qty * 3), 0, 1),
                '_pincode'         => $area['pincode_covered'] ? 1.0 : ($mode === 'local' ? 0.7 : 0.5),
                '_sla'             => 1 - $this->clamp(((int) $slaDays - $this->targetSla) / $this->targetSla, 0, 1),
                '_rating'          => $rating !== null ? $rating / 5 : 0.6,
                '_priority'        => $priority / 100,
            ];
        }

        if (empty($candidates)) {
            return [];
        }

        $maxShipping = max(array_map(fn ($c) => $c['shipping_cost'], $candidates)) ?: 0.0;
        foreach ($candidates as &$c) {
            $shipNorm = $maxShipping > 0 ? $this->clamp($c['shipping_cost'] / $maxShipping, 0, 1) : 0.0;
            $c['score'] = round(
                $this->weights['inv'] * $c['_inv']
                + $this->weights['pincode'] * $c['_pincode']
                + $this->weights['sla'] * $c['_sla']
                + $this->weights['rating'] * $c['_rating']
                + $this->weights['priority'] * $c['_priority']
                - $this->weights['shipping'] * $shipNorm,
                4
            );
            unset($c['_inv'], $c['_pincode'], $c['_sla'], $c['_rating'], $c['_priority']);
        }
        unset($c);

        // LOCAL tier first (fast local), then by score desc, then cheaper price.
        usort($candidates, function ($a, $b) {
            $ta = $a['fulfillment_mode'] === 'local' ? 0 : 1;
            $tb = $b['fulfillment_mode'] === 'local' ? 0 : 1;
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return ($a['selling_price'] ?? INF) <=> ($b['selling_price'] ?? INF);
        });

        $candidates[0]['recommended'] = true;
        for ($i = 1; $i < count($candidates); $i++) {
            $candidates[$i]['recommended'] = false;
        }
        return $candidates;
    }

    /** The single best vendor for a line (auto-assign + checkout estimate), or null. */
    public function bestFor(int $productId, ?int $variationOptionId, int $qty, ?string $city, ?string $pincode = null): ?array
    {
        $c = $this->candidatesFor($productId, $variationOptionId, $qty, $city, $pincode);
        return $c[0] ?? null;
    }

    /**
     * Best service-area match: exact pincode > city (local) > city (courier) > national courier.
     * @return array{mode: ?string, eta_days: ?int, pincode_covered: bool}
     */
    private function matchArea($areas, string $cityN, ?string $pincode): array
    {
        $pincode = $pincode ? trim($pincode) : null;
        $localCity = null;
        $courierCity = null;
        $courierAny = null;

        foreach ($areas as $a) {
            $mode = $a->fulfillment_mode; // local | courier | both
            $cityMatch = $this->norm($a->city) === $cityN && $cityN !== '';
            $pinMatch = $pincode && $a->pincode && trim((string) $a->pincode) === $pincode;

            if ($pinMatch) {
                $m = in_array($mode, ['local', 'both'], true) ? 'local' : 'courier';
                return ['mode' => $m, 'eta_days' => $a->eta_days, 'pincode_covered' => true];
            }
            if ($cityMatch && in_array($mode, ['local', 'both'], true) && $localCity === null) {
                $localCity = $a;
            }
            if ($cityMatch && in_array($mode, ['courier', 'both'], true) && $courierCity === null) {
                $courierCity = $a;
            }
            if (in_array($mode, ['courier', 'both'], true) && $courierAny === null) {
                $courierAny = $a;
            }
        }

        if ($localCity) {
            return ['mode' => 'local', 'eta_days' => $localCity->eta_days, 'pincode_covered' => false];
        }
        if ($courierCity) {
            return ['mode' => 'courier', 'eta_days' => $courierCity->eta_days, 'pincode_covered' => false];
        }
        if ($courierAny) {
            return ['mode' => 'courier', 'eta_days' => $courierAny->eta_days, 'pincode_covered' => false];
        }
        return ['mode' => null, 'eta_days' => null, 'pincode_covered' => false];
    }

    /** Shipping cost for a mode from the vendor's rate rows (zone by locality), else 0. */
    private function shippingCost($rates, string $mode, bool $pincodeCovered): float
    {
        if ($rates->isEmpty()) {
            return 0.0;
        }
        $zone = $mode === 'local' ? 'same_city' : 'national';
        $row = $rates->first(fn ($r) => $r->fulfillment_mode === $mode && $r->zone === $zone)
            ?: $rates->first(fn ($r) => $r->fulfillment_mode === $mode)
            ?: $rates->first();
        return $row ? (float) $row->base_cost : 0.0;
    }

    private function clamp(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }

    private function norm(?string $s): string
    {
        return strtolower(trim((string) $s));
    }
}
