<?php

namespace Marvel\Services\DeliveryOptimizer\Support;

/**
 * Delivery "rail" — the physical lane a leg ships on. This is the second half of
 * a shipment's identity (the first being the vendor): two items consolidate onto
 * one delivery fee only when they share BOTH the same vendor AND the same rail.
 *
 *  - INSTANT : same-city / instant lane (Borzo/Porter). Maps to the shipping-service
 *              `same_city` mode. This is `fulfillment_mode = local` from the candidate.
 *  - COURIER : national courier lane (Shiprocket). Maps to shipping-service `courier`.
 *              This is `fulfillment_mode = courier`.
 *
 * Kept as a tiny pure value-helper (no Eloquent, no container) so it is trivially
 * testable and lifts unchanged into a future Go/Python worker.
 */
final class Rail
{
    public const INSTANT = 'INSTANT';
    public const COURIER = 'COURIER';

    /** Map the candidate's `fulfillment_mode` (local|courier|both) to a rail. */
    public static function fromFulfillmentMode(?string $mode): string
    {
        return $mode === 'courier' ? self::COURIER : self::INSTANT;
    }

    /**
     * Map a rail to the shipping-service `mode` enum (same_city|instant|courier).
     * INSTANT collapses to `same_city` (the Borzo lane CourierService::modeOf uses
     * for non-courier shipments); COURIER -> `courier` (Shiprocket).
     */
    public static function toShippingMode(string $rail): string
    {
        return $rail === self::COURIER ? 'courier' : 'same_city';
    }

    public static function isValid(string $rail): bool
    {
        return $rail === self::INSTANT || $rail === self::COURIER;
    }
}
