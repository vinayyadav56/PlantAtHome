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
    public function __construct(private ?GeoMatchService $geo = null)
    {
        $this->geo = $geo ?: new GeoMatchService();
    }

    public function suggest(Order $order): array
    {
        $customer = $this->resolveCustomerLatLng($order);

        $productIds = $order->relationLoaded('products')
            ? $order->products->pluck('id')->all()
            : $order->products()->pluck('products.id')->all();

        $stockShopIds = $this->shopsWithStock($productIds);

        $vendors  = $this->rankVendors($customer, $stockShopIds);
        $partners = $this->rankPartners($customer, $vendors[0] ?? null);

        $suggestion = $this->buildSuggestion($vendors, $partners);

        return [
            'customer_location'  => $customer,
            'has_customer_coords' => (bool) $customer,
            'eta_source'         => $this->geo->hasKey() ? 'google' : 'haversine',
            'vendors'            => $vendors,
            'partners'           => $partners,
            'suggestion'         => $suggestion,
        ];
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

    private function rankVendors(?array $customer, array $stockShopIds): array
    {
        $shops = Shop::where('is_active', 1)->get()->filter(fn ($s) => $this->shopLatLng($s) !== null);

        $rows = [];
        $dests = [];
        foreach ($shops as $s) {
            $ll = $this->shopLatLng($s);
            $rows[] = [
                'shop_id'   => $s->id,
                'name'      => $s->name,
                'lat'       => $ll['lat'],
                'lng'       => $ll['lng'],
                'has_stock' => in_array($s->id, $stockShopIds, true),
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
        // Prefer in-stock, then nearest.
        usort($rows, function ($a, $b) {
            if ($a['has_stock'] !== $b['has_stock']) {
                return $a['has_stock'] ? -1 : 1;
            }
            return ($a['distance_km'] ?? INF) <=> ($b['distance_km'] ?? INF);
        });
        return array_slice($rows, 0, 15);
    }

    private function rankPartners(?array $customer, ?array $chosenVendor): array
    {
        $dps = DeliveryPartner::where('status', 'approved')->where('is_active', 1)
            ->whereNotNull('lat')->whereNotNull('lng')->get();

        $rows = [];
        $destsC = [];
        foreach ($dps as $dp) {
            $rows[] = [
                'delivery_partner_id' => $dp->id,
                'full_name'           => $dp->full_name,
                'mobile'              => $dp->mobile,
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
        return array_slice($rows, 0, 15);
    }

    private function buildSuggestion(array $vendors, array $partners): array
    {
        $vendor = $vendors[0] ?? null;
        $dp     = $partners[0] ?? null;
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

    private function shopLatLng(Shop $shop): ?array
    {
        $loc = is_array($shop->settings) ? ($shop->settings['location'] ?? null) : null;
        if (is_array($loc) && isset($loc['lat'], $loc['lng']) && is_numeric($loc['lat']) && is_numeric($loc['lng'])) {
            return ['lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng']];
        }
        // Fall back to the lat/lng columns if populated.
        if (is_numeric($shop->lat) && is_numeric($shop->lng)) {
            return ['lat' => (float) $shop->lat, 'lng' => (float) $shop->lng];
        }
        return null;
    }
}
