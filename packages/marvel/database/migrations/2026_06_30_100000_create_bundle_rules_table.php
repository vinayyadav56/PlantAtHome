<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F5 — rule-based bundles. A bundle_rule defines an ELIGIBLE pool of plants
 * (either a hand-picked set or a cost condition evaluated live against vendor
 * cost data), a quantity rule, and a price/discount. It references existing
 * products by id — it never duplicates catalogue rows, and the eligible set is
 * evaluated dynamically so it never goes stale.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('bundle_rules')) {
            return;
        }

        Schema::create('bundle_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            // product_set = hand-picked product ids; cost_condition = live cost filter.
            $table->enum('type', ['product_set', 'cost_condition'])->default('product_set');
            // product_set:    {"product_ids":[1,2,3]}
            // cost_condition: {"operator":"<=","threshold":500,"category_id":12}
            $table->json('eligibility');
            // {"min":1,"max":5}
            $table->json('quantity_rule')->nullable();
            // Fixed bundle price OR a discount off the pool total (mutually exclusive).
            $table->double('bundle_price')->nullable();
            $table->enum('discount_type', ['flat', 'percentage'])->nullable();
            $table->double('discount_value')->nullable();
            $table->boolean('is_active')->default(true)->index();
            // Optional vendor scope (null = catalogue-wide / admin bundle).
            $table->unsignedBigInteger('shop_id')->nullable()->index();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_rules');
    }
};
