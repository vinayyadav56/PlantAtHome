<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bundle Management System — a bundle is a Product (product_type='bundle') whose
 * composition lives in the existing product_inclusions pivot. These 6 columns
 * carry the bundle's own config so no side table is needed:
 *   bundle_type      'manual' | 'auto'  (NULL for non-bundles)
 *   pricing_mode     'fixed' | 'percentage' | 'flat' | 'margin'
 *   pricing_value    the pct / flat amount / fixed price / margin %
 *   display_priority listing sort weight
 *   created_by       admin user id who built it
 *   bundle_config    JSON {min_plants,max_plants,badge,rule:{...}} (limits + auto-regen snapshot)
 * The bundle's own `quantity` stays IGNORED for stock (derived MIN at read time);
 * the computed customer price still lands in price/min_price/max_price.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'bundle_type')) {
                $table->string('bundle_type', 16)->nullable()->index()->after('product_type');
            }
            if (!Schema::hasColumn('products', 'pricing_mode')) {
                $table->string('pricing_mode', 16)->nullable()->after('bundle_type');
            }
            if (!Schema::hasColumn('products', 'pricing_value')) {
                $table->decimal('pricing_value', 14, 2)->nullable()->after('pricing_mode');
            }
            if (!Schema::hasColumn('products', 'display_priority')) {
                $table->unsignedInteger('display_priority')->default(0)->index()->after('pricing_value');
            }
            if (!Schema::hasColumn('products', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->index()->after('display_priority');
            }
            if (!Schema::hasColumn('products', 'bundle_config')) {
                $table->json('bundle_config')->nullable()->after('created_by');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['bundle_type', 'pricing_mode', 'pricing_value', 'display_priority', 'created_by', 'bundle_config'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
