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
            'margin_type'    => ['nullable', 'in:percent,flat'],
            // percent required for percent rules; flat amount for flat rules.
            'margin_percent' => ['required_if:margin_type,percent', 'nullable', 'numeric', 'min:0', 'max:500'],
            'margin_flat'    => ['required_if:margin_type,flat', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'is_active'      => ['nullable', 'boolean'],
        ]);

        $type = $data['margin_type'] ?? 'percent';
        if ($type === 'percent' && !isset($data['margin_percent'])) {
            // Pre-flat clients send margin_percent with no margin_type — keep them working.
            $request->validate(['margin_percent' => ['required', 'numeric', 'min:0', 'max:500']]);
        }

        $cityKey = $this->normalizeCity($data['city'] ?? null);
        // updateOrCreate (not the unique index) enforces one row per pair — MySQL
        // unique indexes allow multiple NULLs (see the migration note).
        $margin = PricingMargin::updateOrCreate(
            ['city' => $cityKey, 'type_id' => $data['type_id'] ?? null],
            [
                'margin_type'    => $type,
                'margin_percent' => (float) ($data['margin_percent'] ?? 0),
                'margin_flat'    => $type === 'flat' ? (float) ($data['margin_flat'] ?? 0) : null,
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
            'margin_type'    => ['nullable', 'in:percent,flat'],
            'margin_percent' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'margin_flat'    => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'is_active'      => ['nullable', 'boolean'],
        ]);
        if (array_key_exists('margin_type', $data) && $data['margin_type'] !== null) {
            $margin->margin_type = $data['margin_type'];
            if ($data['margin_type'] === 'percent') {
                $margin->margin_flat = null;
            }
        }
        if (array_key_exists('margin_percent', $data) && $data['margin_percent'] !== null) {
            $margin->margin_percent = (float) $data['margin_percent'];
        }
        if (array_key_exists('margin_flat', $data) && $data['margin_flat'] !== null) {
            $margin->margin_flat = (float) $data['margin_flat'];
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
