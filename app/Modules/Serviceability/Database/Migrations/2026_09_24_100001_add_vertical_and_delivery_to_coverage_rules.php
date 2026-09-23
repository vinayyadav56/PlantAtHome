<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give a coverage rule a vertical and a delivery promise.
 *
 * `vertical` is a Type slug; '*' (the default, never NULL) means "every
 * vertical". A sentinel keeps (shop_id, target_key) effective as a unique key,
 * which NULL would silently defeat in MySQL. Rules for a named vertical carry
 * it in target_key ("city:42@tools"), so existing '*' rows keep their keys.
 *
 * fulfillment_mode/eta_days move the delivery promise onto the rule, where the
 * vendor declares coverage — until now they lived only on the legacy
 * vendor_service_areas rows the projector writes, so a rules-only vendor was
 * silently 'both' with no ETA.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vendor_coverage_rules') || Schema::hasColumn('vendor_coverage_rules', 'vertical')) {
            return;
        }

        Schema::table('vendor_coverage_rules', function (Blueprint $table) {
            $table->string('vertical', 64)->default('*')->after('rule_type');
            $table->string('fulfillment_mode', 16)->nullable()->after('is_active');
            $table->unsignedSmallInteger('eta_days')->nullable()->after('fulfillment_mode');
            $table->index(['shop_id', 'vertical', 'is_active']);
        });

        // target_key widens: "{type}:{id}@{vertical}" for a named vertical.
        Schema::table('vendor_coverage_rules', function (Blueprint $table) {
            $table->string('target_key', 120)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vendor_coverage_rules', 'vertical')) {
            return;
        }

        Schema::table('vendor_coverage_rules', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'vertical', 'is_active']);
            $table->dropColumn(['vertical', 'fulfillment_mode', 'eta_days']);
        });
    }
};
