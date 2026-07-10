<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product↔group assignments, option/variant compatibility, and per-nursery
 * enablement (Section 4.4). These three tables turn generic groups/options into
 * a per-product, per-size, per-vendor offering — entirely as data.
 *
 * - assignments: attaches a group to a product (with per-product overrides).
 * - compatibility: encodes "large plant → large pot". If an option has NO
 *   compatibility rows it fits every variant; if it has rows, only matching
 *   variant_id / size_code combos are compatible.
 * - enablement: a vendor offers every option by default; an is_enabled=false
 *   row opts a specific option OUT for that nursery.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('config_product_assignments')) {
            Schema::create('config_product_assignments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('group_id');
                $table->boolean('is_required_override')->nullable(); // null = use group default
                $table->unsignedBigInteger('default_option_id')->nullable();
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();

                $table->foreign('product_id')->references('id')->on('catalog_products')->cascadeOnDelete();
                $table->foreign('group_id')->references('id')->on('config_groups')->cascadeOnDelete();
                $table->foreign('default_option_id')->references('id')->on('config_options')->nullOnDelete();
                $table->unique(['product_id', 'group_id']);
            });
        }

        if (! Schema::hasTable('config_option_compatibility')) {
            Schema::create('config_option_compatibility', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('option_id');
                $table->unsignedBigInteger('variant_id')->nullable(); // specific variant
                $table->string('size_code')->nullable();              // OR all variants of a size
                $table->boolean('is_compatible')->default(true);
                $table->timestamps();

                $table->foreign('option_id')->references('id')->on('config_options')->cascadeOnDelete();
                $table->foreign('variant_id')->references('id')->on('catalog_product_variants')->cascadeOnDelete();
                $table->index(['option_id', 'variant_id']);
                $table->index(['option_id', 'size_code']);
            });
        }

        if (! Schema::hasTable('config_nursery_enablement')) {
            Schema::create('config_nursery_enablement', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('nursery_id');
                $table->unsignedBigInteger('option_id');
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();

                $table->foreign('option_id')->references('id')->on('config_options')->cascadeOnDelete();
                $table->unique(['nursery_id', 'option_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('config_nursery_enablement');
        Schema::dropIfExists('config_option_compatibility');
        Schema::dropIfExists('config_product_assignments');
    }
};
