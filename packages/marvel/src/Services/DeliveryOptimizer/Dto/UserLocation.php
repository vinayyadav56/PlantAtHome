<?php

namespace Marvel\Services\DeliveryOptimizer\Dto;

/**
 * The destination the cart is being optimized for. `city`/`pincode` drive the
 * Phase-A serviceability gate; lat/lng (optional) flow into firm quote payloads.
 */
final class UserLocation
{
    public function __construct(
        public readonly ?string $city,
        public readonly ?string $pincode = null,
        public readonly ?float $lat = null,
        public readonly ?float $lng = null,
    ) {
    }

    public static function fromArray(array $a): self
    {
        return new self(
            isset($a['city']) ? (string) $a['city'] : null,
            isset($a['pincode']) ? (string) $a['pincode'] : null,
            isset($a['lat']) ? (float) $a['lat'] : null,
            isset($a['lng']) ? (float) $a['lng'] : null,
        );
    }
}
