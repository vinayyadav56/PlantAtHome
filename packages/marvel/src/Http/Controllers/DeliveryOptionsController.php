<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\City;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\VendorServiceArea;
use Marvel\Services\AvailabilityService;
use Marvel\Services\Courier\ShippingServiceClient;

/**
 * PDP "check delivery" — what a customer at THIS pincode can actually get, and
 * how fast, for THIS product. Returns every option side by side rather than one
 * winner, because the two answers are different products to a customer: a local
 * nursery run today, or a courier parcel in a few days.
 *
 *   instant  — a vendor supplying this product has a LOCAL service area covering
 *              the pincode's city. ETA comes from the vendor's own eta_days.
 *   courier  — a LIVE quote from the shipping service (Shiprocket et al), so the
 *              days shown are the partner's current answer, not a static guess.
 *
 * PUBLIC and unauthenticated, and the courier leg costs money at the partner —
 * so it is rate-limited on the route AND cached per (pincode, product). Without
 * both, this endpoint is a free way to bill our own courier account.
 *
 * Never throws: an unreachable shipping service degrades to "courier available,
 * ETA unknown" rather than an error, because serviceability is still true.
 */
class DeliveryOptionsController extends CoreController
{
    /** How long a (pincode, product) answer stands. Partner ETAs move slowly. */
    private const TTL = 600;

    public function check(Request $request)
    {
        $data = $request->validate([
            'pincode'    => 'required|string|max:10',
            'product_id' => 'nullable|integer',
        ]);
        $pincode = preg_replace('/\D/', '', (string) $data['pincode']);
        if (strlen($pincode) !== 6) {
            return response()->json([
                'serviceable' => false,
                'pincode'     => $pincode,
                'reason'      => 'invalid_pincode',
                'message'     => 'Enter a valid 6-digit pincode.',
                'options'     => [],
            ], 422);
        }

        $productId = (int) ($data['product_id'] ?? 0);

        // ?debug=1, SUPER-ADMIN ONLY: bypass the cache and attach why the
        // courier leg answered the way it did. The client never throws — a
        // rejected body and an unreachable service both degrade to the same
        // silent estimate, so without this the only way to tell them apart is
        // to guess. Never available to the public: it echoes the partner's
        // raw response.
        $debug = $request->boolean('debug')
            && $request->user()
            && $request->user()->hasPermissionTo(\Marvel\Enums\Permission::SUPER_ADMIN);
        if ($debug) {
            $this->debug = [];
            return response()->json($this->resolve($pincode, $productId) + ['_debug' => $this->debug]);
        }

        $key = "delivery-options:v5:{$pincode}:{$productId}";

        return response()->json(
            Cache::remember($key, self::TTL, fn () => $this->resolve($pincode, $productId))
        );
    }

    /** Populated only on a super-admin ?debug=1 request. */
    private ?array $debug = null;

    private function resolve(string $pincode, int $productId): array
    {
        $geo = $this->geoFor($pincode);
        $cityName = $geo['city'] ?? null;

        $out = [
            'pincode'     => $pincode,
            'city'        => $cityName,
            'state'       => $geo['state'] ?? null,
            'serviceable' => false,
            'options'     => [],
        ];

        if (!$cityName) {
            $out['reason'] = 'unknown_pincode';
            $out['message'] = "We don't have this pincode on our map yet.";
            return $out;
        }

        $availability = app(AvailabilityService::class);
        $cityKey = $availability->normalizeCityKey($cityName);
        $variants = $availability->cityKeyVariants($cityKey);

        // Resolve the CANONICAL city, not the postal label. 110001 comes back
        // as "Central Delhi" — a real cities row that is not serviceable on its
        // own — while the vendors, the projection and the operating city are
        // all "Delhi". Matching the raw name blocked the whole of central Delhi
        // with a `city_active` reason that made no sense. Prefer the canonical
        // row and fall back to any alias spelling that exists.
        $city = City::whereRaw('LOWER(name) = ?', [$cityKey])->first()
            ?: City::whereIn(DB::raw('LOWER(name)'), $variants)
                ->orderByDesc('is_serviceable')->first();

        if ($city) {
            // Display the operating city, so "Delivering to Delhi" matches the
            // city the customer picked and the price they were quoted.
            $cityName = $city->name;
            $out['city'] = $cityName;
        }

        // A paused/maintenance city answers before anything else — quoting a
        // courier for a city we aren't serving today would be a lie with a
        // number attached.
        if ($city && !$city->acceptsOrders()) {
            $out['reason'] = $city->status === City::STATUS_ACTIVE
                ? 'city_not_serviceable'
                : 'city_' . $city->status;
            $out['message'] = $city->status === City::STATUS_MAINTENANCE
                ? "We've paused deliveries in {$cityName} for a short while."
                : "We don't deliver to {$cityName} yet.";
            return $out;
        }

        // Vendors who supply THIS product here (or any vendor serving the city
        // when the caller didn't name a product).
        $areas = VendorServiceArea::whereIn(DB::raw('LOWER(city)'), $variants)
            ->where('is_active', true)
            ->when($productId > 0, fn ($q) => $q->whereIn(
                'shop_id',
                DB::table('vendor_product_prices')
                    ->where('product_id', $productId)
                    ->where('is_available', true)
                    // Raw query — the Eloquent approved() scope does not apply here, and
                    // soft-deleted rows were never excluded on this path either.
                    ->where('review_status', 'approved')
                    ->whereNull('deleted_at')
                    ->select('shop_id')
            ))
            ->get();

        if ($areas->isEmpty()) {
            $out['reason'] = 'no_supply';
            $out['message'] = "No nursery delivers this to {$cityName} yet.";
            return $out;
        }

        $out['serviceable'] = true;

        // ── Instant / local ────────────────────────────────────────────────
        $local = $areas->filter(fn ($a) => in_array($a->fulfillment_mode, ['local', 'both'], true));
        if ($local->isNotEmpty()) {
            // The FASTEST vendor wins the badge — that is the promise the
            // customer is being shown. A null eta_days means the vendor never
            // set one; treat it as same-day rather than inventing a number.
            $etas = $local->pluck('eta_days')->filter(fn ($d) => $d !== null)->map(fn ($d) => (int) $d);
            $days = $etas->isNotEmpty() ? $etas->min() : 0;
            $out['options'][] = [
                'type'      => 'instant',
                'label'     => $days <= 0 ? 'Same-day local delivery' : 'Local nursery delivery',
                'eta_days'  => $days,
                'eta_text'  => $this->etaText($days),
                'available' => true,
                'source'    => 'vendor',
                'note'      => 'Delivered by a nursery in your city.',
            ];
        }

        // ── Courier (live partner quote) ───────────────────────────────────
        $courier = $areas->filter(fn ($a) => in_array($a->fulfillment_mode, ['courier', 'both'], true));
        if ($courier->isNotEmpty()) {
            // postal_codes.latitude is unpopulated for most pins, so fall back
            // to the city centroid. The partner (Shiprocket) actually keys its
            // serviceability call off PINCODES, not coordinates — the coords
            // are only there for the partners that do need them.
            if (empty($geo['lat']) && $city) {
                $geo['lat'] = $city->lat;
                $geo['lng'] = $city->lng;
            }
            $out['options'][] = $this->courierOption(
                $courier->pluck('shop_id')->unique()->all(),
                $geo,
                $productId,
            );
        }

        return $out;
    }

    /**
     * One live courier quote, pickup = the vendor's shop, drop = the pincode's
     * centroid. Degrades to an available-but-unknown-ETA option on any failure:
     * the parcel CAN be couriered, we just can't promise a date this second.
     */
    private function courierOption(array $shopIds, array $geo, int $productId): array
    {
        $fallback = [
            'type'      => 'courier',
            'label'     => 'Courier delivery',
            'eta_days'  => null,
            'eta_text'  => 'Usually 3–7 days',
            'available' => true,
            'source'    => 'estimate',
            'note'      => 'Shipped to your door by our courier partner.',
        ];

        try {
            $client = app(ShippingServiceClient::class);
            if (!$client->configured()) {
                if ($this->debug !== null) {
                    $this->debug['configured'] = false;
                }
                return $fallback;
            }
            $shop = Shop::whereIn('id', $shopIds)->first();

            // 4s: a PDP must not sit waiting on a slow partner.
            if ($this->debug !== null) {
                $this->debug['shop_id'] = $shop?->id;
                $this->debug['configured'] = true;
                $this->debug['drop'] = ['pincode' => $geo['pincode'], 'lat' => $geo['lat'], 'lng' => $geo['lng']];
            }

            $res = $client->quoteProspective($shop, [
                'pincode' => (string) $geo['pincode'],
                'city'    => (string) ($geo['city'] ?? ''),
                'state'   => (string) ($geo['state'] ?? ''),
                'lat'     => $geo['lat'],
                'lng'     => $geo['lng'],
            ], $this->weightFor($productId), 4000, $this->quoteItem($productId));

            if ($this->debug !== null) {
                $this->debug['quote_result'] = $res;
            }

            $cheapest = $res['cheapest'] ?? null;
            $days = $cheapest ? (int) ($cheapest['eta_days'] ?? 0) : 0;
            if (!$cheapest || $days <= 0) {
                return $fallback;
            }

            return [
                'type'      => 'courier',
                'label'     => $this->partnerLabel($cheapest['partner'] ?? null),
                'eta_days'  => $days,
                'eta_text'  => $this->etaText($days),
                'available' => true,
                'source'    => 'live',
                'note'      => 'Shipped to your door by our courier partner.',
            ];
        } catch (\Throwable $e) {
            if ($this->debug !== null) {
                $this->debug['exception'] = $e->getMessage();
            }
            return $fallback;
        }
    }

    /** A representative line for the quote — partners size the package from it. */
    private function quoteItem(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }
        try {
            $p = \Marvel\Database\Models\Product::find($productId, ['id', 'name', 'sku', 'price']);
            return $p ? [
                'name'       => (string) $p->name,
                'sku'        => (string) ($p->sku ?: ('SKU-' . $p->id)),
                'qty'        => 1,
                'unit_price' => (float) ($p->price ?? 0),
            ] : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function partnerLabel(?string $partner): string
    {
        return match (strtolower((string) $partner)) {
            'shiprocket' => 'Courier delivery (Shiprocket)',
            'borzo'      => 'Courier delivery (Borzo)',
            'porter'     => 'Courier delivery (Porter)',
            default      => 'Courier delivery',
        };
    }

    private function etaText(int $days): string
    {
        return match (true) {
            $days <= 0 => 'Today',
            $days === 1 => 'Tomorrow',
            default    => "In {$days} days",
        };
    }

    /**
     * Shipping weight in grams. It lives on vendor_product_prices (the vendor's
     * own size sheet), NOT on products — the master catalogue has no weight
     * column. 1kg is the potted-plant default when nobody has entered one; the
     * quote is an ETA estimate, so a rough weight is fine and a missing one is
     * not a reason to withhold the answer.
     */
    private function weightFor(int $productId): int
    {
        if ($productId <= 0) {
            return 1000;
        }
        try {
            $w = (int) DB::table('vendor_product_prices')
                ->where('product_id', $productId)
                ->where('review_status', 'approved')
                ->whereNull('deleted_at')
                ->whereNotNull('weight')
                ->where('weight', '>', 0)
                ->max('weight');
            return $w > 0 ? $w : 1000;
        } catch (\Throwable $e) {
            return 1000;
        }
    }

    /** {city,state,lat,lng} for a pincode from the postal master. */
    private function geoFor(string $pincode): array
    {
        try {
            $row = DB::table('postal_codes')
                ->leftJoin('states', 'states.id', '=', 'postal_codes.state_id')
                ->leftJoin('districts', 'districts.id', '=', 'postal_codes.district_id')
                ->leftJoin('cities', 'cities.id', '=', 'postal_codes.city_id')
                ->where('postal_codes.pincode', $pincode)
                ->first([
                    'states.name as state_name',
                    'districts.name as district_name',
                    'cities.name as city_name',
                    'postal_codes.latitude',
                    'postal_codes.longitude',
                ]);
            if (!$row) {
                return ['pincode' => $pincode, 'lat' => null, 'lng' => null];
            }
            return [
                'pincode' => $pincode,
                'state'   => $row->state_name,
                // Rural pins have no mapped master city; the district name is
                // the locality a customer would recognise.
                'city'    => $row->city_name ?? $row->district_name,
                'lat'     => $row->latitude,
                'lng'     => $row->longitude,
            ];
        } catch (\Throwable $e) {
            return ['pincode' => $pincode, 'lat' => null, 'lng' => null];
        }
    }
}
