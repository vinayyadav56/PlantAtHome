<?php

namespace App\Modules\Serviceability\Http\Controllers;

use App\Modules\Serviceability\Application\CoverageService;
use App\Modules\Serviceability\Http\Requests\SetCoverageRequest;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Vendor coverage management + delivery/availability checks. */
final class CoverageController extends ApiController
{
    public function __construct(private readonly CoverageService $coverage)
    {
    }

    /** PUT /api/v1/serviceability/coverage — vendor-own or admin. */
    public function setCoverage(SetCoverageRequest $request): JsonResponse
    {
        $user = $request->user();
        $nurseryId = (string) $request->input('nursery_id');
        if (! $user->isPlatformAdmin() && (string) $user->nursery_id !== $nurseryId) {
            return $this->fail('FORBIDDEN_SCOPE', 'You can only set coverage for your own nursery.', 403);
        }

        $area = $this->coverage->setCoverage(
            $nurseryId,
            (string) $request->input('city_uuid'),
            (float) $request->input('delivery_radius_km', 0),
            $request->filled('center_lat') ? (float) $request->input('center_lat') : null,
            $request->filled('center_lng') ? (float) $request->input('center_lng') : null,
            $request->boolean('is_active', true),
        );

        return $this->ok([
            'uuid' => $area->uuid, 'nursery_id' => $area->nursery_id,
            'delivery_radius_km' => $area->delivery_radius_km, 'is_active' => (bool) $area->is_active,
        ]);
    }

    /** GET /api/v1/serviceability/availability?city=&product= — public city-aware availability. */
    public function availability(Request $request): JsonResponse
    {
        foreach (['city', 'product'] as $p) {
            if (! $request->query($p)) {
                return $this->fail('MISSING_PARAM', "Query param '{$p}' is required.", 422, $p);
            }
        }

        $result = $this->coverage->productAvailability(
            (string) $request->query('product'),
            (string) $request->query('city'),
        );

        // Public endpoint: expose only whether it's available + how many vendors —
        // never the raw internal supplier nursery_id UUIDs / per-vendor stock.
        return $this->ok([
            'available'             => $result['available'],
            'reason'                => $result['reason'],
            'serving_vendor_count'  => count($result['serving_vendors']),
            'in_stock_vendor_count' => count($result['in_stock_vendors']),
        ]);
    }

    /** POST /api/v1/serviceability/delivery-check — public radius eligibility. */
    public function deliveryCheck(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nursery_id' => ['required', 'uuid'],
            'city_uuid'  => ['required', 'uuid'],
            'lat'        => ['required', 'numeric', 'between:-90,90'],
            'lng'        => ['required', 'numeric', 'between:-180,180'],
        ]);

        $eligible = $this->coverage->withinRadius($data['nursery_id'], $data['city_uuid'], (float) $data['lat'], (float) $data['lng']);

        return $this->ok(['eligible' => $eligible]);
    }
}
