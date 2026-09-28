<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\City;
use Marvel\Services\LocationNormalizer;

/**
 * Server-authoritative reverse geocoding (Shopping-City redesign). The draggable map
 * pin's final lat/lng resolves HERE to {city, district, state, pincode}, normalized
 * against the cities canon — shared by GET geo/reverse and the address save path, so
 * the client can never fabricate its rg_* fields.
 *
 * Resolution ladder (each step fail-open to the next):
 *  1. Google reverse geocode (server key, same convention as GeoMatchService).
 *  2. Postal-master enrichment: extracted pincode → postal_codes join → CANONICAL
 *     state/district/city names (override Google's spellings).
 *  3. No key / no result → nearest `cities` row by haversine, accepted within 50 km.
 * Results cached 1h per ~11 m grid cell (4-decimal rounding).
 */
class ReverseGeocodeService
{
    public function resolve(float $lat, float $lng): array
    {
        $cacheKey = sprintf('geo_rev:%.4f:%.4f', $lat, $lng);

        return Cache::remember($cacheKey, now()->addHour(), function () use ($lat, $lng) {
            // Must declare EVERY key the return shape reads: extractComponents() is merged in only
            // when a key is configured, so on the keyless path these would otherwise be undefined.
            $out = [
                'city' => null, 'district' => null, 'state' => null, 'pincode' => null,
                'country' => null, 'country_code' => null, 'area' => null,
            ];
            $formatted = null; // Google's full street-level formatted_address (best result).

            // config/location.php is the ONE resolution point — it reads both env spellings and
            // Settings → Integrations overlays it at boot. The `?: env('GOOGLE_MAP_API_KEY')` that
            // used to sit here was dead code in production: config:cache makes env() return null,
            // so this silently skipped Google and fell through to the 50km guess below.
            $key = config('location.google_maps_key');
            if (!$key) {
                // Without this the degradation is invisible: the response still looks like a real
                // answer (city + state), just with pincode and formatted_address permanently null.
                Log::warning('geo.reverse.no_server_key', [
                    'hint' => 'Set Settings → Integrations → Google Maps → Server API Key. Falling back to nearest-city.',
                ]);
            }
            $source = $key ? 'google' : 'nearest_city';

            if ($key) {
                try {
                    // 8s was a checkout-path hazard: a Google incident became an 8s "Locating..."
                    // hang on every pin drag. Geocode answers well inside a second in practice.
                    $json = Http::timeout(4)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                        'latlng' => $lat . ',' . $lng,
                        'key'    => $key,
                    ])->json();

                    // A rejected or throttled key returns `results: []` - byte-identical to a
                    // genuine ZERO_RESULTS. Without reading `status` the two are indistinguishable
                    // and both fall silently through to the nearest-city guess, which is precisely
                    // how a key problem hides. This is the line that makes a bad key visible.
                    $status = (string) ($json['status'] ?? '');
                    if ($status !== '' && $status !== 'OK' && $status !== 'ZERO_RESULTS') {
                        Log::warning('geo.reverse.google_rejected', [
                            'status' => $status,
                            'error'  => $json['error_message'] ?? null,
                        ]);
                    }

                    $results = (array) ($json['results'] ?? []);
                    $out = array_merge($out, $this->extractComponents($results));
                    $formatted = $results[0]['formatted_address'] ?? null;
                } catch (\Throwable $e) {
                    // fail-open to the fallbacks below
                    Log::warning('geo.reverse.google_failed', ['error' => $e->getMessage()]);
                }
            }

            if (!empty($out['pincode'])) {
                $geo = $this->postalGeo($out['pincode']);
                foreach (['state', 'district', 'city'] as $f) {
                    if (!empty($geo[$f])) {
                        $out[$f] = $geo[$f];
                    }
                }
            }

            if (empty($out['city'])) {
                // Reached whenever Google produced no city - which includes a key that is present
                // but rejected, throttled or timing out, NOT only the keyless case. Stamping the
                // source from `$key` alone would report 'google' while handing back a 50km guess.
                $source = 'nearest_city';
                $nearest = $this->nearestCity($lat, $lng, 50.0);
                if ($nearest) {
                    $out['city'] = $nearest->name;
                    $out['state'] = $out['state'] ?: $nearest->state_name;
                }
            }

            // Split city from district BEFORE anything persists this. Google labels a Saket pin
            // "South Delhi", and the fallback at extractComponents() promotes a district into the
            // city slot whenever there is no `locality` at all — so without this every caller
            // (rg_city, verified_city, the admin geo/reverse endpoint) stored a district as a city.
            $canonical = app(LocationNormalizer::class)->normalize([
                'city'     => $out['city'],
                'district' => $out['district'],
                'state'    => $out['state'],
                'zip'      => $out['pincode'],
            ]);
            $out['city']     = $canonical['city'] ?: $out['city'];
            $out['district'] = $canonical['district'] ?: $out['district'];
            $out['state']    = $canonical['state'] ?: $out['state'];

            $normalized = $out['city']
                ? app(AvailabilityService::class)->normalizeCityKey($out['city'])
                : null;
            $canon = $canonical['city_id']
                ? City::query()->find($canonical['city_id'])
                : ($normalized ? City::query()->whereRaw('LOWER(name) = ?', [$normalized])->first() : null);

            return [
                'city'              => $out['city'],
                'district'          => $out['district'],
                'state'             => $out['state'],
                'pincode'           => $out['pincode'],
                'formatted_address' => $formatted, // full street-level address from the pin's coordinates
                'normalized_city'   => $normalized,
                'city_id'           => $canon?->id,
                'is_serviceable'    => $canon ? (bool) $canon->acceptsOrders() : false,

                // Added for the address form, which previously had to leave Country and Area for
                // the shopper to type. Both are additive — existing consumers keep their keys.
                'country'           => $out['country'],
                'country_code'      => $out['country_code'],
                'area'              => $out['area'],

                // Lets a caller tell a real Google answer from a 50km nearest-city guess. Set
                // where the fallback actually runs, not from `$key` - a present-but-rejected key
                // still falls back, and reporting 'google' for that would defeat the point.
                'source'            => $source,
            ];
        });
    }

    /**
     * Area/locality candidates in descending specificity. Google does NOT guarantee component
     * order within a result, so collect every candidate and choose by this priority afterwards
     * rather than letting whichever appeared first win — `route` beating `sublocality_level_1`
     * would put a street name in the Area field.
     */
    private const AREA_TYPES = ['sublocality_level_1', 'sublocality', 'neighborhood', 'route', 'premise'];

    /** Pull country/city/district/state/pincode/area out of Google results (best result wins per field). */
    private function extractComponents(array $results): array
    {
        $out = [
            'city' => null, 'district' => null, 'state' => null, 'pincode' => null,
            'country' => null, 'country_code' => null, 'area' => null,
        ];
        $areaByType = [];

        foreach ($results as $result) {
            foreach ((array) ($result['address_components'] ?? []) as $c) {
                $types = (array) ($c['types'] ?? []);
                $name = (string) ($c['long_name'] ?? '');
                if ($name === '') {
                    continue;
                }
                if (!$out['city'] && (in_array('locality', $types) || in_array('postal_town', $types))) {
                    $out['city'] = $name;
                }
                if (!$out['district'] && (in_array('administrative_area_level_3', $types) || in_array('administrative_area_level_2', $types))) {
                    $out['district'] = $name;
                }
                if (!$out['state'] && in_array('administrative_area_level_1', $types)) {
                    $out['state'] = $name;
                }
                if (!$out['pincode'] && in_array('postal_code', $types)) {
                    $out['pincode'] = preg_replace('/\D/', '', $name);
                }
                if (!$out['country'] && in_array('country', $types)) {
                    $out['country'] = $name;
                    $out['country_code'] = (string) ($c['short_name'] ?? '') ?: null;
                }
                foreach (self::AREA_TYPES as $t) {
                    if (!isset($areaByType[$t]) && in_array($t, $types)) {
                        $areaByType[$t] = $name;
                    }
                }
            }
            if ($out['city'] && $out['state'] && $out['pincode']) {
                break;
            }
        }

        foreach (self::AREA_TYPES as $t) {
            if (!empty($areaByType[$t])) {
                $out['area'] = $areaByType[$t];
                break;
            }
        }
        // The area must not simply restate the city ("Bengaluru, Bengaluru") — that reads as a
        // filled field to the shopper while carrying no extra information.
        if ($out['area'] && $out['city'] && mb_strtolower($out['area']) === mb_strtolower($out['city'])) {
            $out['area'] = null;
        }
        // Urban India often labels the city at admin level 3/2 while `locality` is a
        // neighbourhood — surface the district as the city fallback.
        if (!$out['city'] && $out['district']) {
            $out['city'] = $out['district'];
        }
        return $out;
    }

    /** Canonical names for a pin from the postal master (same join as DeliveryPincodeController). */
    private function postalGeo(string $pincode): array
    {
        try {
            $geo = DB::table('postal_codes')
                ->leftJoin('states', 'states.id', '=', 'postal_codes.state_id')
                ->leftJoin('districts', 'districts.id', '=', 'postal_codes.district_id')
                ->leftJoin('cities', 'cities.id', '=', 'postal_codes.city_id')
                ->where('postal_codes.pincode', $pincode)
                ->first(['states.name as state_name', 'districts.name as district_name', 'cities.name as city_name']);
            if (!$geo) {
                return [];
            }
            return [
                'state'    => $geo->state_name,
                'district' => $geo->district_name,
                'city'     => $geo->city_name, // canon city only — no district fallback here
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Nearest cities-canon row by haversine, within $maxKm. */
    private function nearestCity(float $lat, float $lng, float $maxKm): ?object
    {
        try {
            $rows = City::query()
                ->whereNotNull('lat')->whereNotNull('lng')
                ->get(['id', 'name', 'state_name', 'lat', 'lng']);
            $best = null;
            $bestKm = INF;
            foreach ($rows as $c) {
                $dLat = deg2rad((float) $c->lat - $lat);
                $dLng = deg2rad((float) $c->lng - $lng);
                $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad((float) $c->lat)) * sin($dLng / 2) ** 2;
                $km = 6371.0 * 2 * asin(min(1, sqrt($a)));
                if ($km < $bestKm) {
                    $bestKm = $km;
                    $best = $c;
                }
            }
            return ($best && $bestKm <= $maxKm) ? $best : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
