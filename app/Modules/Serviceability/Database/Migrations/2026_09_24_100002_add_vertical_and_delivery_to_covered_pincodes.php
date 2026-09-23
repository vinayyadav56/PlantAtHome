<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The projection gains the same vertical + delivery promise as the rules it
 * flattens. One vendor now projects one row per (pincode, vertical), so the
 * unique key widens to match — without that, a tools rule and a plants rule
 * for the same pin would collide and the second would be dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vendor_covered_pincodes') || Schema::hasColumn('vendor_covered_pincodes', 'vertical')) {
            return;
        }

        Schema::table('vendor_covered_pincodes', function (Blueprint $table) {
            $table->string('vertical', 64)->default('*')->after('pincode');
            $table->string('fulfillment_mode', 16)->nullable()->after('source');
            $table->unsignedSmallInteger('eta_days')->nullable()->after('fulfillment_mode');
        });

        Schema::table('vendor_covered_pincodes', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'pincode']);
            $table->unique(['shop_id', 'pincode', 'vertical']);
        });

        // The per-state gate (DeliveryCoverageService::coverageConfiguredFor)
        // asks "does anyone project into this state?" on every public check.
        Schema::table('vendor_covered_pincodes', function (Blueprint $table) {
            $table->index('state_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vendor_covered_pincodes', 'vertical')) {
            return;
        }

        Schema::table('vendor_covered_pincodes', function (Blueprint $table) {
            $table->dropIndex(['state_id']);
            $table->dropUnique(['shop_id', 'pincode', 'vertical']);
            $table->dropColumn(['vertical', 'fulfillment_mode', 'eta_days']);
            $table->unique(['shop_id', 'pincode']);
        });
    }
};
