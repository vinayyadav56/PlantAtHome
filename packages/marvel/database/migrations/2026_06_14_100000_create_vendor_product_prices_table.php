<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor cost sheets: per-vendor (shop) cost for a product (+ optional size =
 * variation_option_id), time-versioned (weekly/monthly) via effective_from/to.
 * cost=0 ⇒ is_available=false (product orderable but flagged "available in 6h").
 * The customer never sees cost_price — selling price = margin over the nearest
 * available cost (PricingService). Uploaded via admin-only Excel (batch audit).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('vendor_product_prices')) {
            return;
        }
        Schema::create('vendor_product_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->index();              // vendor
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('variation_option_id')->nullable()->index(); // size (null = whole product)
            $table->decimal('cost_price', 14, 2)->default(0);
            $table->string('period_type')->default('weekly');            // weekly | monthly
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_available')->default(true);              // cost_price > 0
            $table->string('source')->default('excel');                  // excel | manual
            $table->unsignedBigInteger('import_batch_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'effective_from', 'effective_to'], 'vpp_product_period_idx');
            $table->index(['shop_id', 'product_id', 'variation_option_id'], 'vpp_vendor_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_product_prices');
    }
};
