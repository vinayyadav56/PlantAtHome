<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single-shop cutover:
 *  1. Find-or-create THE master "PlantAtHome" shop (slug `plantathome`).
 *  2. Every catalog product moves to the master shop — vendor shops become pure
 *     suppliers (their vendor_product_prices / service areas are untouched).
 *  3. Attributes become GLOBAL (shop_id NULL) — one platform attribute list feeds
 *     the master catalog + vendor inventory pricing.
 *  4. products.proposed_by_shop_id — attribution for vendor-proposed catalog
 *     products (drives the admin review queue).
 *
 * Idempotent: re-running finds the master shop and re-applies the (no-op) updates.
 * Historic ORDERS keep their original shop_id — money history is never rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shops') || !Schema::hasTable('products')) {
            return;
        }

        // 1. Master shop (find-or-create; owner = first super admin when present).
        $master = DB::table('shops')->where('slug', 'plantathome')->first();
        if (!$master) {
            $ownerId = null;
            try {
                $ownerId = DB::table('model_has_permissions')
                    ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
                    ->where('permissions.name', 'super_admin')
                    ->where('model_has_permissions.model_type', 'Marvel\\Database\\Models\\User')
                    ->value('model_has_permissions.model_id');
            } catch (\Throwable $e) {
                // permissions tables absent on a bare install — fall through
            }
            // shops.owner_id is NOT NULL, so a bare install (fresh CI database,
            // migrations run before any seeder creates users) cannot host the
            // master shop yet. Fall back to any existing user; if there are no
            // users at all, skip creation entirely — steps 2/3 below are no-ops
            // on an empty database, and the master shop is created on real
            // environments (which always have users) or by the seeders.
            $ownerId = $ownerId ?: DB::table('users')->value('id');
            if (!$ownerId) {
                return;
            }
            $masterId = DB::table('shops')->insertGetId([
                'name'       => 'PlantAtHome',
                'slug'       => 'plantathome',
                'owner_id'   => $ownerId,
                'is_active'  => 1,
                'settings'   => json_encode(['is_master' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $masterId = (int) $master->id;
            DB::table('shops')->where('id', $masterId)->update(['is_active' => 1]);
        }

        // 2. All products → master shop.
        DB::table('products')->where(function ($q) use ($masterId) {
            $q->where('shop_id', '!=', $masterId)->orWhereNull('shop_id');
        })->update(['shop_id' => $masterId]);

        // 3. Attributes → global.
        if (Schema::hasTable('attributes') && Schema::hasColumn('attributes', 'shop_id')) {
            DB::table('attributes')->whereNotNull('shop_id')->update(['shop_id' => null]);
        }

        // 4. Vendor-proposal attribution.
        if (!Schema::hasColumn('products', 'proposed_by_shop_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedBigInteger('proposed_by_shop_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // Product ownership + attribute scoping are a one-way consolidation (the
        // original per-vendor ownership is not preserved); only the new column drops.
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'proposed_by_shop_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('proposed_by_shop_id');
            });
        }
    }
};
