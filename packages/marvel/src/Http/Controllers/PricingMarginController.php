<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Marvel\Database\Models\PricingMargin;
use Marvel\Services\AvailabilityService;
use Marvel\Services\MarginResolver;

/**
 * Super-admin CRUD for the PlantAtHome selling-margin matrix (see MarginResolver).
 * Every mutation flushes the resolver cache and recomputes the per-city price
 * projection (product_city_availability.min_price embeds the margin) so the
 * storefront reflects the new margin without a deploy.
 */
class PricingMarginController extends CoreController
{
    /** GET pricing-margins — full matrix, global default first, then by specificity. */
    public function index(Request $request)
    {
        return PricingMargin::with('type:id,name,slug')
            ->orderByRaw('(city IS NOT NULL) + (type_id IS NOT NULL)') // global → single-dim → city+vertical
            ->orderBy('city')
            ->get();
    }

    /** POST pricing-margins { city?, type_id?, margin_percent, is_active? } — upsert on (city, type_id). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'city'           => ['nullable', 'string', 'max:100'],
            'type_id'        => ['nullable', 'integer', 'exists:types,id'],
            'margin_percent' => ['required', 'numeric', 'min:0', 'max:500'],
            'is_active'      => ['nullable', 'boolean'],
        ]);

        $cityKey = $this->normalizeCity($data['city'] ?? null);
        // updateOrCreate (not the unique index) enforces one row per pair — MySQL
        // unique indexes allow multiple NULLs (see the migration note).
        $margin = PricingMargin::updateOrCreate(
            ['city' => $cityKey, 'type_id' => $data['type_id'] ?? null],
            [
                'margin_percent' => (float) $data['margin_percent'],
                'is_active'      => (bool) ($data['is_active'] ?? true),
            ]
        );

        $this->refreshPricing();
        return $margin->fresh('type:id,name,slug');
    }

    /** PUT pricing-margins/{id} { margin_percent?, is_active? } — the (city,type) key is immutable. */
    public function update(Request $request, $id)
    {
        $margin = PricingMargin::findOrFail((int) $id);
        $data = $request->validate([
            'margin_percent' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'is_active'      => ['nullable', 'boolean'],
        ]);
        if (array_key_exists('margin_percent', $data) && $data['margin_percent'] !== null) {
            $margin->margin_percent = (float) $data['margin_percent'];
        }
        if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
            $margin->is_active = (bool) $data['is_active'];
        }
        $margin->save();

        $this->refreshPricing();
        return $margin->fresh('type:id,name,slug');
    }

    /** DELETE pricing-margins/{id} */
    public function destroy(Request $request, $id)
    {
        $margin = PricingMargin::findOrFail((int) $id);
        $margin->delete();

        $this->refreshPricing();
        return ['id' => (int) $id, 'deleted' => true];
    }

    private function normalizeCity(?string $city): ?string
    {
        if ($city === null || trim($city) === '') {
            return null;
        }
        return (new AvailabilityService())->normalizeCityKey($city);
    }

    /** Flush the resolver + rebuild the projection so new margins go live immediately. */
    private function refreshPricing(): void
    {
        MarginResolver::flush();
        AvailabilityService::bustCatalogCache();
        try {
            // Queue when a worker exists; the queue driver on this stack is sync-safe
            // (falls back to running inline), and the daily scheduled run is the backstop.
            Artisan::queue('marvel:recompute-city-availability');
        } catch (\Throwable $e) {
            try {
                Artisan::call('marvel:recompute-city-availability');
            } catch (\Throwable $e2) {
                // never fail the admin request over a projection refresh
            }
        }
    }
}
