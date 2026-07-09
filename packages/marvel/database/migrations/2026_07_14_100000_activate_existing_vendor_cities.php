<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill: the storefront city picker lists is_serviceable cities, and vendor
 * onboarding now auto-activates the cities a vendor serves (ShopRepository::
 * activateServedCities). Existing vendors were onboarded BEFORE that hook, so
 * their cities (vendor_service_areas.city + the shop's own address city) may
 * still be is_serviceable = false. Activate them once. Never touches cities in
 * the deliberately DISABLED state; never deactivates anything. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasTable('cities') ||
            !Schema::hasTable('vendor_service_areas') ||
            !Schema::hasTable('shops')
        ) {
            return;
        }
        if (DB::connection()->getDriverName() !== 'mysql') {
            return; // JSON_EXTRACT below is MySQL-specific
        }

        $names = [];

        foreach (DB::table('vendor_service_areas')->where('is_active', 1)->pluck('city') as $c) {
            $c = mb_strtolower(trim((string) $c));
            if ($c !== '') {
                $names[$c] = true;
            }
        }

        foreach (
            DB::table('shops')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(address, '$.city')) IS NOT NULL")
                ->pluck(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(address, '$.city')) as city")) as $c
        ) {
            $c = mb_strtolower(trim((string) $c));
            if ($c !== '' && $c !== 'null') {
                $names[$c] = true;
            }
        }

        foreach (array_chunk(array_keys($names), 200) as $chunk) {
            DB::table('cities')
                ->whereIn(DB::raw('LOWER(name)'), $chunk)
                ->where('status', '!=', 'disabled')
                ->where('is_serviceable', false)
                ->update(['is_serviceable' => true]);
        }
    }

    public function down(): void
    {
        // Data backfill — nothing sensible to reverse.
    }
};
