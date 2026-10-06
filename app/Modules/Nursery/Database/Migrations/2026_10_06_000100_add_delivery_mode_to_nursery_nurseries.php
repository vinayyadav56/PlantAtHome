<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vendor's fulfilment choice, on the V2 nursery row.
 *
 * `delivery_mode` / `self_delivery` have lived only on legacy `shops` (2026_08_11_000400).
 * On staging the admin's vendor Update goes to V2 — and V2 had no columns, no fillable
 * entries and no validation for them — so the whole "Delivery & fulfilment" step silently
 * saved nothing there, while saving fine on production where Update is still the legacy
 * PUT. Same shape as the legacy columns so the admin form round-trips unchanged.
 *
 * Guarded per column, like 000200: staging has run partial nursery migrations before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nursery_nurseries')) {
            return;
        }
        Schema::table('nursery_nurseries', function (Blueprint $table) {
            if (! Schema::hasColumn('nursery_nurseries', 'delivery_mode')) {
                // platform | self — mirrors shops.delivery_mode
                $table->string('delivery_mode', 16)->default('platform');
            }
            if (! Schema::hasColumn('nursery_nurseries', 'self_delivery')) {
                $table->json('self_delivery')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('nursery_nurseries')) {
            return;
        }
        Schema::table('nursery_nurseries', function (Blueprint $table) {
            foreach (['delivery_mode', 'self_delivery'] as $col) {
                if (Schema::hasColumn('nursery_nurseries', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
