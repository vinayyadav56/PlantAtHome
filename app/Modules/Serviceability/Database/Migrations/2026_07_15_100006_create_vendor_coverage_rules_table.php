<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Coverage — a vendor's declared coverage, as rules over the geo
 * master (whole state / district / city, or individual include/exclude pins).
 * target_key = "{rule_type}:{id-or-pin}" makes (shop_id, target_key) the
 * natural dedupe key for upserts and replace-all syncs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_coverage_rules')) {
            return;
        }

        Schema::create('vendor_coverage_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->index();
            $table->string('rule_type', 20);
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('pincode', 10)->nullable()->index();
            $table->string('target_key', 48);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'target_key']);
            $table->index(['shop_id', 'rule_type', 'is_active']);

            if (Schema::hasTable('shops')) {
                $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            }
            if (Schema::hasTable('states')) {
                $table->foreign('state_id')->references('id')->on('states')->nullOnDelete();
            }
            $table->foreign('district_id')->references('id')->on('districts')->nullOnDelete();
            if (Schema::hasTable('cities')) {
                $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_coverage_rules');
    }
};
