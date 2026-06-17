<?php

namespace Marvel\Services\Courier;

/**
 * The seam between PlantAtHome and a 3rd-party courier aggregator. CourierService talks
 * ONLY to this interface, so a second provider (Delhivery direct, NimbusPost, …) can be
 * swapped in by implementing it — no caller changes. Every method returns a structured
 * array (never throws for an API-level failure) so the domain layer handles partial
 * failures explicitly:  ['ok' => bool, 'status' => int, 'data' => mixed, 'error' => ?string].
 */
interface CourierProviderInterface
{
    /** Couriers + rates + ETA serviceable for pickup→drop at the given weight (COD or prepaid). */
    public function serviceability(string $pickupPin, string $dropPin, int $weightGrams, bool $cod): array;

    /** Create an ad-hoc order/shipment with the provider. $payload is provider-shaped (built by CourierService). */
    public function createOrder(array $payload): array;

    /** Allocate an AWB (courier waybill) to a created shipment; optional preferred courier id. */
    public function assignAwb(string $providerShipmentId, ?int $courierId = null): array;

    /** Generate the shipping label PDF for one or more provider shipments. */
    public function generateLabel(array $providerShipmentIds): array;

    /** Schedule pickup for one or more provider shipments. */
    public function schedulePickup(array $providerShipmentIds): array;

    /** Live tracking (status + scan history) by AWB or provider shipment id. */
    public function track(string $awbOrShipmentId): array;

    /** Register a pickup location (a vendor address) with the provider; returns its nickname. */
    public function addPickupLocation(array $address): array;

    /** List the registered pickup locations (for idempotent sync). */
    public function listPickupLocations(): array;
}
