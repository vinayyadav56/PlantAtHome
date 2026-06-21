<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\GlobalVerticalSetting as GVS;
use Marvel\Database\Models\Type;

/**
 * Operations Control Center — seed one ACTIVE global row per vertical (from the
 * Type table) + the 2 service verticals + the reserved __platform__ row.
 *
 * Idempotent + NON-DESTRUCTIVE: `firstOrCreate` only creates missing rows, so an
 * admin's toggles are never reset on redeploy, and every vertical defaults to
 * active (fail-open ⇒ go-live is a no-op until something is switched off).
 */
class ServiceAvailabilitySeeder extends Seeder
{
    public function run(): void
    {
        $slugs = Type::query()->whereNotNull('slug')->distinct()->pluck('slug')->all();
        $slugs = array_values(array_unique(array_merge($slugs, GVS::SERVICE_VERTICALS)));

        foreach ($slugs as $slug) {
            GVS::firstOrCreate(
                ['vertical_slug' => $slug],
                ['is_active' => true, 'status' => 'active']
            );
        }

        // Reserved platform row (kill-switch / maintenance) — all flags off.
        GVS::firstOrCreate(
            ['vertical_slug' => GVS::PLATFORM_SLUG],
            [
                'is_active' => true,
                'status'    => 'active',
                'settings'  => ['stop_platform' => false, 'stop_orders' => false, 'stop_deliveries' => false, 'maintenance' => false],
            ]
        );
    }
}
