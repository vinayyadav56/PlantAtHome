<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-shipment package overrides for courier booking.
 *
 * Weight was computed from product weights with a 500 g/unit fallback and
 * dimensions never left the monolith at all (the Shiprocket adapter carried
 * hardcoded 20x15x15 literals, and the L/B/H inputs on the courier settings
 * page were read by nothing). An operator who can see the parcel needs to be
 * able to correct both — and the correction has to reach the partner, because
 * couriers price on volumetric weight.
 *
 * NULL on every column = derive exactly as before (product data → settings
 * default → 20x15x15), so existing shipments are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->unsignedInteger('weight_g')->nullable()->after('cod_amount');
            $table->decimal('length_cm', 6, 2)->nullable()->after('weight_g');
            $table->decimal('breadth_cm', 6, 2)->nullable()->after('length_cm');
            $table->decimal('height_cm', 6, 2)->nullable()->after('breadth_cm');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['weight_g', 'length_cm', 'breadth_cm', 'height_cm']);
        });
    }
};
