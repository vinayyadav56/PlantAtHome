<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the F5 "rule-based bundles" offer engine. It was an admin-only staging
 * feature that never reached a customer and is fully superseded by the Bundle
 * Management System (a bundle is a Product + product_inclusions). Its CRUD
 * controller, model and routes are removed in the same change.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('bundle_rules');
    }

    public function down(): void
    {
        if (Schema::hasTable('bundle_rules')) {
            return;
        }
        Schema::create('bundle_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['product_set', 'cost_condition'])->default('product_set');
            $table->json('eligibility');
            $table->json('quantity_rule')->nullable();
            $table->double('bundle_price')->nullable();
            $table->enum('discount_type', ['flat', 'percentage'])->nullable();
            $table->double('discount_value')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('shop_id')->nullable()->index();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
