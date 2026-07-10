<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuration groups + options (Section 4.4). A group (Pot, Packaging,
 * Accessories, …) is a generic, reusable concept — NOTHING is special-cased in
 * code. Each option is itself a SKU with its own independent inventory (Phase 5)
 * and price (Phase 6); base_price here is the admin reference only.
 * Prefixed config_* to coexist with the legacy marvel schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('config_groups')) {
            Schema::create('config_groups', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->string('code')->unique();          // pot, packaging, accessories, …
                $table->string('name');
                $table->string('select_type')->default('single'); // single | multi
                $table->boolean('is_required')->default(false);
                $table->unsignedInteger('sort')->default(0);
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('config_options')) {
            Schema::create('config_options', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('group_id');
                $table->string('sku')->unique();
                $table->string('name');
                $table->unsignedInteger('weight_grams')->nullable();
                $table->decimal('base_price', 12, 2)->default(0);   // admin reference price
                $table->char('currency', 3)->default('INR');
                $table->string('status')->default('active');        // active | inactive
                $table->unsignedInteger('sort')->default(0);
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('group_id')->references('id')->on('config_groups')->cascadeOnDelete();
                $table->index(['group_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('config_options');
        Schema::dropIfExists('config_groups');
    }
};
