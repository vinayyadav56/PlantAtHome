<?php

namespace Marvel\Services;

use Marvel\Database\Models\DeliveryPartner;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\VendorProductPrice;

/**
 * Matches an order to the nearest available vendor + nearest delivery partner.
 *
 * Customer coords come from the order's shipping_address.location, else are
 * geocoded from the address (needs a Maps key). Vendors are active shops with a
 * settings.location; ranked by distance to the customer, preferring those that
 * stock the ordered products. DPs are approved+active partners with coords;
 * ranked by distance to the customer (and to the chosen vendor), preferring a
 * vendor-cum-DP that is the chosen vendor. Distances/ETA via GeoMatchService
 * (Haversine always; Google road ETA when keyed). The result feeds the admin
 * order panel; the admin approves/reassigns the final assignment.
 */
class MatchingService
{
    /** A vendor/DP outside the order's city still counts as "same area" within this radius. */
    private const RADIUS_KM = 25;

    public function __construct(private ?GeoMatchService $geo = null)
    {
        $this->geo = $geo ?: new GeoMatchService();
    }

    public function suggest(Order $order): array
    {
        $customer  = $this->resolveCustomerLatLng($order);
        $orderCity = $this->resolveCustomerCity($order);
        // Only scope to the city/area when we have *something* to scope by — else
        // show everyone (preserves behavior for orders with no city and no coords).
        $canFilter = ($orderCity !== null) || ($customer !== null);

        $productIds = $order->relationLoaded('products')
            ? $order->products->pluck('id')->all()
            : $order->products()->pluck('products.id')->all();

        $stockShopIds = $this->shopsWithStock($productIds);
        $quotes = $this->vendorQuotes($productIds);
        $lineCount = count(array_unique($productIds));

        [$vendors, $vendorsOther] = $this->partition(
            $this->rankVendors($customer, $orderCity, $canFilter, $stockShopIds, $quotes, $lineCount)
        );
        $chosenVendor = $vendors[0] ?? $vendorsOther[0] ?? null;

        [$partners, $partnersOther] = $this->partition(
            $this->rankPartners($customer, $orderCity, $canFilter, $chosenVendor)
        );

        $suggestion = $this->buildSuggestion($vendors, $vendorsOther, $partners, $partnersOther);

        return [
            'order_city'          => $orderCity,
            'customer_location'   => $customer,
            'has_customer_coords' => (bool) $customer,
            'eta_source'          => $this->geo->hasKey() ? 'google' : 'haversine',
            'vendors'             => $vendors,        // same city / within radius
            'vendors_other'       => $vendorsOther,   // everyone else, ranked by distance
            'partners'            => $partners,
            'partners_other'      => $partnersOther,
            'suggestion'          => $suggestion,
        ];
    }

    /** Split a ranked list into same-city (primary) + other, capping each. */
    private function partition(array $rows, int $limit = 15): array
    {
        $same = [];
        $other = [];
        foreach ($rows as $r) {
            if (!empty($r['same_city'])) {
                $same[] = $r;
            } else {
                $other[] = $r;
            }
        }
        return [array_slice($same, 0, $limit), array_slice($other, 0, $limit)];
    }

    private function normCity(?string $s): string
    {
        return strtolower(trim((string) $s));
    }

    /** Same city by name (case-insensitive) OR within RADIUS_KM. Show-all when nothing to filter by. */
    private function isSameCity(bool $canFilter, ?string $orderCity, ?string $rowCity, $distanceKm): bool
    {
        if (!$canFilter) {
            return true;
        }
        if ($orderCity && $rowCity && $this->normCity($rowCity) === $this->normCity($orderCity)) {
            return true;
        }
        return is_numeric($distanceKm) && (float) $distanceKm <= self::RADIUS_KM;
    }

    private function resolveCustomerCity(Order $order): ?string
    {
        foreach ([$order->shipping_address, $order->billing_address] as $addr) {
            if (is_array($addr) && isset($addr['city']) && trim((string) $addr['city']) !== '') {
                return trim((string) $addr['city']);
            }
        }
        return null;
    }

    private function shopCity(Shop $shop): ?string
    {
        $addr = is_array($shop->address) ? $shop->address : [];
        if (!empty($addr['city'])) {
            return (string) $addr['city'];
        }
        $settings = is_array($shop->settings) ? $shop->settings : [];
        return !empty($settings['city']) ? (string) $settings['city'] : null;
    }

    /** Persist a suggestion (assignment_status=suggested) without overwriting an admin-approved one. */
    public function persistSuggestion(Order $order): array
    {
        $match = $this->suggest($order);
        if (($order->assignment_status ?? 'unassigned') !== 'approved') {
            $s = $match['suggestion'];
            $order->forceFill([
                'vendor_shop_id'      => $s['vendor_shop_id'] ?? $order->vendor_shop_id,
                'delivery_partner_id' => $s['delivery_partner_id'] ?? $order->delivery_partner_id,
                'delivery_mode'       => $s['delivery_mode'] ?? $order->delivery_mode,
                'assignment_status'   => $s['vendor_shop_id'] ? 'suggested' : 'unassigned',
            ])->save();
        }
        return $match;
    }

    private function resolveCustomerLatLng(Order $order): ?array
    {
        $addr = $order->shipping_address ?? $order->billing_address ?? null;
        $loc  = is_array($addr) ? ($addr['location'] ?? null) : null;
        if (is_array($loc) && isset($loc['lat'], $loc['lng']) && is_numeric($loc['lat']) && is_numeric($loc['lng'])) {
            return ['lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng']];
        }
        // Fall back to geocoding the structured address (needs a Maps key).
        return is_array($addr) ? $this->geo->geocode($addr) : null;
    }

    /** Shop ids that have ANY of the ordered products available right now. */
    private function shopsWithStock(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        return VendorProductPrice::whereIn('product_id', $productIds)
            ->where('is_available', true)
            ->where('cost_price', '>', 0)
            ->distinct()
            ->pluck('shop_id')
            ->all();
    }

    /**
     * Per-vendor quote for THIS order's lines: what each vendor charges for the
     * products they can supply, and how many of the order's lines that covers.
     *
     * The picker previously showed only distance + an "in stock / no cost
     * sheet" chip, so an operator choosing between two vendors 8 km and 93 km
     * away had no idea what either would cost.
     *
     * @return array<int, array{total: float, lines: int}> keyed by shop_id
     */
    private function vendorQuotes(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        $rows = VendorProductPrice::whereIn('product_id', $productIds)
            ->where('is_available', true)
            ->get(['shop_id', 'product_id', 'vendor_selling_price', 'cost_price']);

        $out = [];
        foreach ($rows as $r) {
            $price = (float) ($r->vendor_selling_price ?: $r->cost_price);
            if ($price <= 0) {
                continue;
            }
            $shop = (int) $r->shop_id;
            $pid = (int) $r->product_id;
            // A vendor can have several rows per product (variants/periods) —
            // count each PRODUCT once, at its cheapest row.
            if (!isset($out[$shop])) {
                $out[$shop] = ['total' => 0.0, 'lines' => 0, 'seen' => []];
            }
            if (isset($out[$shop]['seen'][$pid])) {
                if ($price < $out[$shop]['seen'][$pid]) {
                    $out[$shop]['total'] += $price - $out[$shop]['seen'][$pid];
                    $out[$shop]['seen'][$pid] = $price;
                }
                continue;
            }
            $out[$shop]['seen'][$pid] = $price;
            $out[$shop]['total'] += $price;
            $out[$shop]['lines']++;
        }
        foreach ($out as $shop => $d) {
            $out[$shop] = ['total' => round($d['total'], 2), 'lines' => $d['lines']];
        }
        return $out;
    }

    private function rankVendors(
        ?array $customer,
        ?string $orderCity,
        bool $canFilter,
        array $stockShopIds,
        array $quotes = [],
        int $lineCount = 0
    ): array {
        $shops = Shop::where('is_active', 1)->get()->filter(fn ($s) => $this->shopLatLng($s) !== null);

        $rows = [];
        $dests = [];
        foreach ($shops as $s) {
            $ll = $this->shopLatLng($s);
            $rows[] = [
                'shop_id'   => $s->id,
                'name'      => $s->name,
                'city'      => $this->shopCity($s),
                'lat'       => $ll['lat'],
                'lng'       => $ll['lng'],
                'has_stock' => in_array($s->id, $stockShopIds, true),
                // What this vendor charges for the lines they can supply, and
                // how many of the order's lines that covers — so "8.7 km" can
                // be weighed against an actual price.
                'quote_total'    => $quotes[$s->id]['total'] ?? null,
                'quote_lines'    => $quotes[$s->id]['lines'] ?? 0,
                'order_lines'    => $lineCount,
                'covers_all'     => $lineCount > 0 && ($quotes[$s->id]['lines'] ?? 0) >= $lineCount,
            ];
            $dests[] = $ll;
        }
        if ($customer && $dests) {
            $d = $this->geo->distances($customer, $dests);
            foreach ($rows as $i => &$r) {
                $r['distance_km']  = $d[$i]['distance_km'] ?? null;
                $r['duration_min'] = $d[$i]['duration_min'] ?? null;
                $r['eta_source']   = $d[$i]['source'] ?? 'haversine';
            }
            unset($r);
        }
        foreach ($rows as &$r) {
            $r['same_city'] = $this->isSameCity($canFilter, $orderCity, $r['city'] ?? null, $r['distance_km'] ?? null);
        }
        unset($r);
        // Prefer in-stock, then nearest. (suggest() partitions same-city vs other.)
        usort($rows, function ($a, $b) {
            if ($a['has_stock'] !== $b['has_stock']) {
                return $a['has_stock'] ? -1 : 1;
            }
            return ($a['distance_km'] ?? INF) <=> ($b['distance_km'] ?? INF);
        });
        return $rows;
    }

    private function rankPartners(?array $customer, ?string $orderCity, bool $canFilter, ?array $chosenVendor): array
    {
        $dps = DeliveryPartner::where('status', 'approved')->where('is_active', 1)
            ->whereNotNull('lat')->whereNotNull('lng')->get();

        $rows = [];
        $destsC = [];
        foreach ($dps as $dp) {
            $addr = is_array($dp->address) ? $dp->address : [];
            $rows[] = [
                'delivery_partner_id' => $dp->id,
                'full_name'           => $dp->full_name,
                'mobile'              => $dp->mobile,
                'city'                => $addr['city'] ?? null,
                'is_vendor_cum_dp'    => (bool) $dp->is_vendor_cum_dp,
                'shop_id'             => $dp->shop_id,
                'lat'                 => (float) $dp->lat,
                'lng'                 => (float) $dp->lng,
            ];
            $destsC[] = ['lat' => (float) $dp->lat, 'lng' => (float) $dp->lng];
        }
        if ($customer && $destsC) {
            $dc = $this->geo->distances($customer, $destsC);
            foreach ($rows as $i => &$r) {
                $r['distance_from_customer'] = $dc[$i]['distance_km'] ?? null;
                $r['eta_to_customer_min']    = $dc[$i]['duration_min'] ?? null;
                $r['eta_source']             = $dc[$i]['source'] ?? 'haversine';
            }
            unset($r);
        }
        if ($chosenVendor && isset($chosenVendor['lat'])) {
            $vorigin = ['lat' => $chosenVendor['lat'], 'lng' => $chosenVendor['lng']];
            $dv = $this->geo->distances($vorigin, $destsC);
            foreach ($rows as $i => &$r) {
                $r['distance_from_vendor'] = $dv[$i]['distance_km'] ?? null;
                $r['eta_to_vendor_min']    = $dv[$i]['duration_min'] ?? null;
            }
            unset($r);
        }
        foreach ($rows as &$r) {
            $r['same_city'] = $this->isSameCity($canFilter, $orderCity, $r['city'] ?? null, $r['distance_from_customer'] ?? null);
        }
        unset($r);
        // Prefer the chosen vendor's own vendor-cum-DP, then nearest to the vendor (fast pickup).
        $chosenShopId = $chosenVendor['shop_id'] ?? null;
        usort($rows, function ($a, $b) use ($chosenShopId) {
            $aOwn = $chosenShopId && $a['is_vendor_cum_dp'] && $a['shop_id'] == $chosenShopId;
            $bOwn = $chosenShopId && $b['is_vendor_cum_dp'] && $b['shop_id'] == $chosenShopId;
            if ($aOwn !== $bOwn) {
                return $aOwn ? -1 : 1;
            }
            $key = 'distance_from_vendor';
            return ($a[$key] ?? $a['distance_from_customer'] ?? INF) <=> ($b[$key] ?? $b['distance_from_customer'] ?? INF);
        });
        return $rows;
    }

    private function buildSuggestion(array $vendors, array $vendorsOther, array $partners, array $partnersOther): array
    {
        $vendor = $vendors[0] ?? $vendorsOther[0] ?? null;
        $dp     = $partners[0] ?? $partnersOther[0] ?? null;
        $mode   = null;
        if ($vendor && $dp) {
            $mode = ($dp['is_vendor_cum_dp'] && $dp['shop_id'] == $vendor['shop_id']) ? 'vendor_dp' : 'separate_dp';
        }
        return [
            'vendor_shop_id'      => $vendor['shop_id'] ?? null,
            'delivery_partner_id' => $dp['delivery_partner_id'] ?? null,
            'delivery_mode'       => $mode,
        ];
    }

    /** @see GeoMatchService::shopLatLng — one reader, so vendor matching and
     *  courier pickup can never disagree about where a shop is. */
    private function shopLatLng(Shop $shop): ?array
    {
        return $this->geo->shopLatLng($shop);
    }
}
