<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\Http;

/**
 * Distance / ETA helper for vendor + delivery-partner matching.
 *
 * Straight-line distance (Haversine) is always available and free. When a
 * server-side Google key is configured (GOOGLE_MAPS_SERVER_KEY) it ALSO provides
 * real road distance + ETA (Distance Matrix) and address→lat/lng (Geocoding).
 * Every Google call degrades gracefully to Haversine / null so matching never
 * breaks when the key is absent or the API errors.
 */
class GeoMatchService
{
    private ?string $key;

    public function __construct()
    {
        // config first so a key managed in Settings → Integrations overlays the env var.
        $this->key = config('location.google_maps_key') ?: env('GOOGLE_MAP_API_KEY') ?: null;
    }

    public function hasKey(): bool
    {
        return !empty($this->key);
    }

    /**
     * Where a shop ships FROM, or null if it has never been pinned.
     *
     * The nursery onboarding LocationPicker writes settings.location{lat,lng};
     * the shops.lat/lng columns are the matching engine's copy of the same point
     * (LocationCaptureService writes both). Read settings first, then columns —
     * this ordering used to be duplicated privately in MatchingService and
     * ShippingServiceClient, which is how the two could disagree about where a
     * vendor is.
     */
    public function shopLatLng($shop): ?array
    {
        $loc = is_array($shop->settings ?? null) ? ($shop->settings['location'] ?? null) : null;
        if (is_array($loc) && isset($loc['lat'], $loc['lng']) && is_numeric($loc['lat']) && is_numeric($loc['lng'])) {
            return ['lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng']];
        }
        if (is_numeric($shop->lat ?? null) && is_numeric($shop->lng ?? null)) {
            return ['lat' => (float) $shop->lat, 'lng' => (float) $shop->lng];
        }
        return null;
    }

    /** Great-circle distance in km. */
    public function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return round($r * 2 * asin(min(1, sqrt($a))), 3);
    }

    /**
     * Road distance + ETA from one origin to many destinations (Distance Matrix).
     * Returns array indexed like $dests: ['distance_km'=>float, 'duration_min'=>float, 'source'=>'google'|'haversine'].
     * Falls back to Haversine per-destination on any failure.
     */
    public function distances(array $origin, array $dests): array
    {
        $fallback = array_map(
            fn ($d) => [
                'distance_km'  => $this->haversineKm($origin['lat'], $origin['lng'], $d['lat'], $d['lng']),
                'duration_min' => null,
                'source'       => 'haversine',
            ],
            $dests
        );

        if (!$this->hasKey() || empty($dests)) {
            return $fallback;
        }

        try {
            $destStr = implode('|', array_map(fn ($d) => "{$d['lat']},{$d['lng']}", $dests));
            $resp = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/distancematrix/json', [
                'origins'      => "{$origin['lat']},{$origin['lng']}",
                'destinations' => $destStr,
                'mode'         => 'driving',
                'key'          => $this->key,
            ]);
            $json = $resp->json();
            if (($json['status'] ?? '') !== 'OK') {
                return $fallback;
            }
            $elements = $json['rows'][0]['elements'] ?? [];
            $out = [];
            foreach ($dests as $i => $d) {
                $el = $elements[$i] ?? null;
                if ($el && ($el['status'] ?? '') === 'OK') {
                    $out[$i] = [
                        'distance_km'  => round(($el['distance']['value'] ?? 0) / 1000, 2),
                        'duration_min' => round(($el['duration']['value'] ?? 0) / 60, 0),
                        'source'       => 'google',
                    ];
                } else {
                    $out[$i] = $fallback[$i];
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /** Geocode a free-form / structured address to lat/lng (null without a key or on failure). */
    public function geocode(?array $address): ?array
    {
        if (!$this->hasKey() || empty($address)) {
            return null;
        }
        $parts = array_filter([
            $address['street_address'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['zip'] ?? null,
            $address['country'] ?? null,
        ]);
        if (empty($parts)) {
            return null;
        }
        try {
            $resp = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => implode(', ', $parts),
                'key'     => $this->key,
            ]);
            $json = $resp->json();
            $loc = $json['results'][0]['geometry']['location'] ?? null;
            if ($loc && isset($loc['lat'], $loc['lng'])) {
                return ['lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng']];
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }
}
