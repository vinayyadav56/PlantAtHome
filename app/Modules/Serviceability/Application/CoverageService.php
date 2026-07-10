<?php

namespace App\Modules\Serviceability\Application;

use App\Modules\Catalog\Infrastructure\Models\Product as CatalogProduct;
use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Inventory\Domain\SellableType;
use App\Modules\Serviceability\Domain\Events\VendorCoverageChanged;
use App\Modules\Serviceability\Infrastructure\Models\City;
use App\Modules\Serviceability\Infrastructure\Models\VendorArea;
use App\Shared\Application\DomainActionException;
use App\Shared\Events\EventPublisher;
use Illuminate\Support\Facades\DB;

/**
 * Serviceability / coverage (Section 4.7). Answers "does a vendor serve this
 * city?", "which vendors serve it?", "is this delivery point within a vendor's
 * radius?", and the composed "is this product available in this city?" (a
 * serving vendor must also have stock). Reads Catalog + Inventory to compose
 * city-aware availability (Section 3 sanctioned reads).
 */
class CoverageService
{
    public function __construct(
        private readonly EventPublisher $events,
        private readonly InventoryService $inventory,
    ) {
    }

    public function createCity(string $name, ?string $state = null): City
    {
        return City::create(['name' => $name, 'state' => $state, 'is_active' => true]);
    }

    /** Set (or update) a vendor's coverage of a city. Emits VendorCoverageChanged. */
    public function setCoverage(string $nurseryId, string $cityUuid, float $radiusKm, ?float $lat, ?float $lng, bool $active = true): VendorArea
    {
        $city = $this->cityOrFail($cityUuid);

        $area = DB::transaction(function () use ($nurseryId, $city, $radiusKm, $lat, $lng, $active) {
            $area = VendorArea::updateOrCreate(
                ['nursery_id' => $nurseryId, 'city_id' => $city->id],
                ['delivery_radius_km' => $radiusKm, 'center_lat' => $lat, 'center_lng' => $lng, 'is_active' => $active],
            );
            $this->events->publish(new VendorCoverageChanged($nurseryId, $city->uuid, $active));

            return $area;
        });

        return $area;
    }

    public function removeCoverage(string $nurseryId, string $cityUuid): void
    {
        $city = $this->cityOrFail($cityUuid);
        DB::transaction(function () use ($nurseryId, $city) {
            VendorArea::where('nursery_id', $nurseryId)->where('city_id', $city->id)->delete();
            $this->events->publish(new VendorCoverageChanged($nurseryId, $city->uuid, false));
        });
    }

    public function isServiceable(string $nurseryId, string $cityUuid): bool
    {
        $city = $this->cityOrFail($cityUuid);

        return VendorArea::where('nursery_id', $nurseryId)->where('city_id', $city->id)->where('is_active', true)->exists();
    }

    /** @return array<int,string> active serving nursery ids for a city */
    public function vendorsServingCity(string $cityUuid): array
    {
        $city = $this->cityOrFail($cityUuid);

        return VendorArea::where('city_id', $city->id)->where('is_active', true)->pluck('nursery_id')->unique()->values()->all();
    }

    public function isCityServiceable(string $cityUuid): bool
    {
        return $this->vendorsServingCity($cityUuid) !== [];
    }

    /** Local-delivery eligibility: is a point within a vendor's radius of a city? */
    public function withinRadius(string $nurseryId, string $cityUuid, float $lat, float $lng): bool
    {
        $city = $this->cityOrFail($cityUuid);
        $area = VendorArea::where('nursery_id', $nurseryId)->where('city_id', $city->id)->where('is_active', true)->first();
        if (! $area) {
            return false;
        }
        // No centre / zero radius ⇒ whole city is served.
        if ($area->center_lat === null || $area->center_lng === null || (float) $area->delivery_radius_km <= 0) {
            return true;
        }

        return $this->haversineKm((float) $area->center_lat, (float) $area->center_lng, $lat, $lng) <= (float) $area->delivery_radius_km;
    }

    /**
     * City-aware product availability: a product is available in a city iff some
     * vendor serving that city has stock for at least one of its variants.
     *
     * @return array{available:bool, reason:?string, serving_vendors:array, in_stock_vendors:array}
     */
    public function productAvailability(string $productUuid, string $cityUuid): array
    {
        $vendors = $this->vendorsServingCity($cityUuid);
        if ($vendors === []) {
            return ['available' => false, 'reason' => 'no_vendor_serves_city', 'serving_vendors' => [], 'in_stock_vendors' => []];
        }

        $product = CatalogProduct::where('uuid', $productUuid)->with('variants')->first();
        if (! $product) {
            throw DomainActionException::notFound('Product not found.', 'PRODUCT_NOT_FOUND');
        }

        $inStock = [];
        foreach ($vendors as $nurseryId) {
            foreach ($product->variants as $variant) {
                if ($this->inventory->available(SellableType::VARIANT, $variant->uuid, $nurseryId) > 0) {
                    $inStock[] = $nurseryId;
                    break;
                }
            }
        }
        $inStock = array_values(array_unique($inStock));

        return [
            'available'        => $inStock !== [],
            'reason'           => $inStock !== [] ? null : 'out_of_stock_in_city',
            'serving_vendors'  => $vendors,
            'in_stock_vendors' => $inStock,
        ];
    }

    private function cityOrFail(string $cityUuid): City
    {
        $city = City::where('uuid', $cityUuid)->first();
        if (! $city) {
            throw DomainActionException::unprocessable('City not found.', 'CITY_NOT_FOUND', 'city');
        }

        return $city;
    }

    /** Great-circle distance in km. */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0; // earth radius km
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
