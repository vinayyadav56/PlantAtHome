<?php

/**
 * Location Capture Email System — admin-triggered GPS capture links for
 * customers and vendors. Everything operational is tunable here/env.
 */
return [
    // How long a capture link stays valid.
    'capture_link_expiry_hours' => (int) env('LOCATION_CAPTURE_EXPIRY_HOURS', 72),

    // Public capture page base. Defaults to this API's own hosted page —
    // override if the page ever moves to the storefront domain.
    // {token} is appended: {base}/{token}
    'capture_base_url' => env('LOCATION_CAPTURE_BASE_URL'), // null => url('/location')

    // GPS readings worse than this (metres) are rejected with a friendly
    // "try again outdoors / enable precise location" message. 0 disables.
    'min_accuracy_meters' => (int) env('LOCATION_MIN_ACCURACY_METERS', 500),

    // Spam guard: max capture emails per target (user/vendor) per day.
    'max_daily_requests' => (int) env('LOCATION_MAX_DAILY_REQUESTS', 5),

    // Hard-block order dispatch/booking when the customer has no verified
    // location. OFF by default — existing customers have no verified
    // location yet; flipping this on day one would freeze every dispatch.
    // Admin UI shows warnings either way.
    'require_verified_for_dispatch' => (bool) env('LOCATION_REQUIRE_VERIFIED_DISPATCH', false),

    /*
     * Google server key for reverse geocoding and distance.
     *
     * THIS IS THE ONE RESOLUTION POINT. Settings → Integrations → Google Maps
     * stores its `server_key` credential against this exact config key
     * (ProviderRegistry), and ConfigOverlay writes it in at boot — so the admin
     * panel overlays whatever is here without a deploy.
     *
     * Both env spellings are honoured because both were already in use: this
     * file read GOOGLE_MAPS_SERVER_KEY while ReverseGeocodeService,
     * GeoMatchService and LocationCaptureService each fell back to
     * `env('GOOGLE_MAP_API_KEY')` INLINE. Those inline fallbacks could never
     * fire — production runs config:cache, under which env() returns null — so
     * a key set under the second name was silently ignored and every pin fell
     * through to a 50km nearest-city guess that returned no pincode at all.
     * env() belongs in a config file, and only here.
     */
    'google_maps_key' => env('GOOGLE_MAPS_SERVER_KEY') ?: env('GOOGLE_MAP_API_KEY'),
];
