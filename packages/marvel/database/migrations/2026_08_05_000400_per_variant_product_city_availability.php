<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-VARIANT city availability.
 *
 * The projection computed each variant's MAX vendor rate but collapsed them to
 * one min_price per (product, city) — so a variant-level display price never
 * survived to the read path, and per-city stock had nowhere to live.
 *
 * New shape: one row per (product, city, variation_option_id), where
 * variation_option_id = 0 is the PRODUCT ROLLUP row (0, not NULL — MySQL
 * unique indexes allow unlimited duplicate NULLs, which would break the
 * upsert key). The rollup row keeps today's exact semantics (min_price =
 * cheapest variant's city price, vendor_count = distinct vendors), so every
 * existing reader keeps working by scoping variation_option_id = 0.
 *
 * New columns:
 *   display_price  — the variant's city selling price (margin applied)
 *   stock          — aggregated vendor stock (NULL = a supplying vendor does
 *                    not track stock, i.e. effectively unlimited)
 *   stock_override — admin manual override; effective stock = override ?? stock
 *
 * Existing rows become the rollup (variation_option_id 0) automatically via
 * the column default. A full recompute after deploy fills the variant rows —
 * see marvel:recompute-city-availability.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('product_city_availability')) {
            return;
        }
        Schema::table('product_city_availability', function (Blueprint $table) {
            if (!Schema::hasColumn('product_city_availability', 'variation_option_id')) {
                $table->unsignedBigInteger('variation_option_id')->default(0)->after('city');
            }
            if (!Schema::hasColumn('product_city_availability', 'display_price')) {
                $table->decimal('display_price', 14, 2)->nullable()->after('min_price');
            }
            if (!Schema::hasColumn('product_city_availability', 'stock')) {
                $table->integer('stock')->nullable()->after('display_price');
            }
            if (!Schema::hasColumn('product_city_availability', 'stock_override')) {
                $table->integer('stock_override')->nullable()->after('stock');
            }
        });

        // Replace the (product, city) unique with (product, city, variant), and
        // fold the variant into the city read index. Index existence isn't
        // introspectable via Schema, so drop-by-name defensively.
        try {
            DB::statement('ALTER TABLE product_city_availability DROP INDEX pca_product_city_uq');
        } catch (\Throwable $e) {
            // already dropped / never existed on this environment
        }
        try {
            DB::statement('ALTER TABLE product_city_availability ADD UNIQUE pca_product_city_variant_uq (product_id, city, variation_option_id)');
        } catch (\Throwable $e) {
        }
        try {
            DB::statement('ALTER TABLE product_city_availability DROP INDEX pca_city_local_idx');
        } catch (\Throwable $e) {
        }
        try {
            DB::statement('ALTER TABLE product_city_availability ADD INDEX pca_city_variant_local_idx (city, variation_option_id, has_local)');
        } catch (\Throwable $e) {
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('product_city_availability')) {
            return;
        }
        // Variant rows would violate the old (product, city) unique — remove them first.
        DB::table('product_city_availability')->where('variation_option_id', '!=', 0)->delete();
        try {
            DB::statement('ALTER TABLE product_city_availability DROP INDEX pca_product_city_variant_uq');
        } catch (\Throwable $e) {
        }
        try {
            DB::statement('ALTER TABLE product_city_availability ADD UNIQUE pca_product_city_uq (product_id, city)');
        } catch (\Throwable $e) {
        }
        try {
            DB::statement('ALTER TABLE product_city_availability DROP INDEX pca_city_variant_local_idx');
        } catch (\Throwable $e) {
        }
        try {
            DB::statement('ALTER TABLE product_city_availability ADD INDEX pca_city_local_idx (city, has_local)');
        } catch (\Throwable $e) {
        }
        Schema::table('product_city_availability', function (Blueprint $table) {
            foreach (['variation_option_id', 'display_price', 'stock', 'stock_override'] as $col) {
                if (Schema::hasColumn('product_city_availability', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
