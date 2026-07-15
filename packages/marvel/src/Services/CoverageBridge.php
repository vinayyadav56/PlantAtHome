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

    /** Test hook: reset the once-per-request warning latch. */
    public static function resetWarning(): void
    {
        self::$warned = false;
    }
}
