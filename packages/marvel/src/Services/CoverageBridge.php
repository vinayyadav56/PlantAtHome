<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\Log;

/**
 * The single legacy → V2 seam for the Delivery Coverage system. Every marvel
 * call site (checkout gate, pincode check, order snapshot, admin/vendor
 * coverage endpoints) resolves the Serviceability module's coverage service
 * through here and treats a null return as "coverage unavailable — fail open".
 * This is deliberately the ONLY legacy file that names a V2 class, so the
 * module can move/rename without a package-wide sweep.
 */
final class CoverageBridge
{
    /** Warn once per request/process — not once per call site. */
    private static bool $warned = false;

    public static function service(): ?\App\Modules\Serviceability\Application\DeliveryCoverageService
    {
        try {
            return app(\App\Modules\Serviceability\Application\DeliveryCoverageService::class);
        } catch (\Throwable $e) {
            if (!self::$warned) {
                self::$warned = true;
                Log::warning('CoverageBridge: DeliveryCoverageService unavailable — coverage features fail open', [
                    'error' => $e->getMessage(),
                ]);
            }
            return null;
        }
    }

    /**
     * The one serviceability resolver ("can vendor V deliver vertical X to
     * pincode P?"). Same fail-open contract as service(): null means the
     * module is unavailable and the caller behaves as it did before coverage.
     */
    public static function resolver(): ?\App\Modules\Serviceability\Application\VendorServiceabilityResolver
    {
        try {
            return app(\App\Modules\Serviceability\Application\VendorServiceabilityResolver::class);
        } catch (\Throwable $e) {
            if (!self::$warned) {
                self::$warned = true;
                Log::warning('CoverageBridge: VendorServiceabilityResolver unavailable — coverage features fail open', [
                    'error' => $e->getMessage(),
                ]);
            }
            return null;
        }
    }

    /**
     * Which of these vendors may serve this pincode — the one narrowing every
     * read path applies, so the PDP badge, the price ETA, the estimate and the
     * checkout gate cannot disagree.
     *
     * Returns NULL for "do not narrow": the gate is off, no pincode was given,
     * or the coverage module is unavailable. Otherwise a shop_id => true set.
     *
     * Opt-in is built in, and it is per-vendor AND per-vertical: a vendor is
     * only measured against a vertical it could answer — one with rules for
     * '*' or for that vertical. A vendor that declared tools coverage never
     * declared plants coverage, so its plants lines pass rather than blocking
     * on a scope it never wrote.
     *
     * @param  int[]  $shopIds
     * @return array<int,true>|null
     */
    public static function allowedShops(array $shopIds, ?string $pincode, ?string $vertical = null): ?array
    {
        try {
            $pin = preg_replace('/\D/', '', (string) $pincode);
            if ($shopIds === [] || !preg_match('/^\d{6}$/', (string) $pin)) {
                return null;
            }
            $settings = \Marvel\Database\Models\Settings::getData();
            if (empty($settings['options']['coverageCheckoutGate'])) {
                return null;
            }
            $resolver = self::resolver();
            if ($resolver === null) {
                return null;
            }

            $shopIds = array_values(array_unique(array_map('intval', $shopIds)));
            $scope = (string) ($vertical ?: '*');
            $covered = $resolver->vendorsFor((string) $pin, $scope);
            $configured = array_flip(
                \Illuminate\Support\Facades\DB::table('vendor_coverage_rules')
                    ->whereIn('shop_id', $shopIds)->where('is_active', 1)
                    ->whereIn('vertical', array_unique(['*', $scope]))
                    ->distinct()->pluck('shop_id')->map(fn ($id) => (int) $id)->all()
            );

            $allowed = [];
            foreach ($shopIds as $shopId) {
                if (isset($covered[$shopId]) || !isset($configured[$shopId])) {
                    $allowed[$shopId] = true;
                }
            }

            return $allowed;
        } catch (\Throwable $e) {
            Log::warning('CoverageBridge: coverage narrowing failed open', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** A product's vertical (Type slug), for scoping coverage rules. */
    public static function verticalOfProduct(int $productId): ?string
    {
        try {
            return \Illuminate\Support\Facades\DB::table('products')
                ->join('types', 'products.type_id', '=', 'types.id')
                ->where('products.id', $productId)->value('types.slug');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Test hook: reset the once-per-request warning latch. */
    public static function resetWarning(): void
    {
        self::$warned = false;
    }
}
