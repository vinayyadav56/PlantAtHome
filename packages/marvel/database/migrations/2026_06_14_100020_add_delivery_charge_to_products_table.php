<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product delivery charge (₹). Checkout delivery_fee = Σ qty × delivery_charge
 * across the cart (PlantAtHome charges delivery per plant, not a flat fee).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('products', 'delivery_charge')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('delivery_charge', 10, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'delivery_charge')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('delivery_charge');
            });
        }
    }
};
