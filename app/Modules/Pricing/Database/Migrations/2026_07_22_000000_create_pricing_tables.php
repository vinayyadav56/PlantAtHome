<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing inputs (Section 4.6). The final price is COMPUTED by PricingService
 * (Section 7), never stored on the product — only these inputs + the persisted
 * breakdown snapshot exist. Money is DECIMAL(12,2) + currency, never float.
 * Price rules (discounts / conditional-free) are NOT a table here — they live in
 * the Rules Engine (PRICING scope, Phase 4). Prefixed pricing_*.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pricing_base_prices')) {
            Schema::create('pricing_base_prices', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->string('sellable_type');                 // variant | option
                $table->uuid('sellable_uuid');
                $table->decimal('amount', 12, 2)->default(0);
                $table->char('currency', 3)->default('INR');
                $table->uuid('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['sellable_type', 'sellable_uuid'], 'pricing_base_sku_unique');
            });
        }

        if (! Schema::hasTable('pricing_vendor_overrides')) {
            Schema::create('pricing_vendor_overrides', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->uuid('nursery_id');
                $table->string('sellable_type');
                $table->uuid('sellable_uuid');
                $table->decimal('amount', 12, 2);
                $table->char('currency', 3)->default('INR');
                $table->uuid('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['nursery_id', 'sellable_type', 'sellable_uuid'], 'pricing_override_unique');
            });
        }

        if (! Schema::hasTable('pricing_tax_rules')) {
            Schema::create('pricing_tax_rules', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->string('name')->nullable();
                $table->uuid('category_uuid')->nullable()->index(); // GST by category
                $table->string('hsn_code')->nullable();
                $table->decimal('gst_rate', 5, 2)->default(0);      // percent, e.g. 18.00
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_tax_rules');
        Schema::dropIfExists('pricing_vendor_overrides');
        Schema::dropIfExists('pricing_base_prices');
    }
};
