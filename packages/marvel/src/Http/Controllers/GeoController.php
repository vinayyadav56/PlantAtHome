<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Services\ReverseGeocodeService;

/**
 * GET geo/reverse?lat&lng — server-authoritative reverse geocoding for the Shopping-City
 * redesign. The draggable map pin's final coordinates resolve to {city, district, state,
 * pincode} + the cities-canon match here; the client never decides the city. All the
 * resolution/caching logic lives in ReverseGeocodeService (shared with the address save
 * path, which re-derives rg_* server-side).
 */
class GeoController extends CoreController
{
    public function reverse(Request $request, ReverseGeocodeService $geocoder)
    {
        $lat = (float) $request->query('lat');
        $lng = (float) $request->query('lng');
        if (!$lat || !$lng || abs($lat) > 90 || abs($lng) > 180) {
            return response()->json(['message' => 'lat and lng are required.'], 422);
        }
        return $geocoder->resolve($lat, $lng);
    }
}
