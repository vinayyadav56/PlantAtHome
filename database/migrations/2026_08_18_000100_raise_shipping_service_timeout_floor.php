<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shipping-service timeout was stored as 15 seconds in the Integrations module.
 *
 * The Go service allows a partner 25 seconds before giving up and returning a considered error
 * (for example Shiprocket's "KYC verification is mandated for your account to ship an order").
 * At 15 we hung up ten seconds before that answer could arrive, so every slow partner call
 * surfaced as "Network error contacting shipping service." and the real reason was never seen.
 *
 * ShippingServiceClient::defaultTimeout() now floors this in code, so the stored value can no
 * longer break the ordering. This migration fixes the data as well, so the number an operator
 * reads in the Integrations UI matches what actually happens.
 *
 * Only raises: a value already above the floor is somebody's deliberate choice and is left alone.
 */
return new class extends Migration
{
    private const FLOOR = 35;

    public function up(): void
    {
        if (!Schema::hasTable('integration_providers')) {
            return;
        }

        foreach (DB::table('integration_providers')->where('provider_slug', 'shipping_service')->get() as $row) {
            $cfg = json_decode($row->configuration ?? 'null', true);
            if (!is_array($cfg)) {
                continue;
            }
            // Absent means "fall back to config", which is already floored — leave it absent.
            if (!array_key_exists('timeout', $cfg) || (int) $cfg['timeout'] >= self::FLOOR) {
                continue;
            }
            $cfg['timeout'] = self::FLOOR;
            DB::table('integration_providers')->where('id', $row->id)->update([
                'configuration' => json_encode($cfg),
                'updated_at'    => now(),
            ]);
        }
    }

    /**
     * Irreversible on purpose. The old value was a misconfiguration that broke error reporting;
     * restoring it would reintroduce the bug, and we do not record which rows we touched.
     */
    public function down(): void
    {
    }
};
