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

    // Google reverse-geocoding key (reuses the existing server key).
    'google_maps_key' => env('GOOGLE_MAPS_SERVER_KEY'),
];
