<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotions (Section 4.10): coupons that feed the Pricing engine (Section 7
 * step 5). Redemptions are logged for usage limits + analytics. Prefixed promo_*
 * (legacy marvel owns `coupons`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('promo_coupons')) {
            Schema::create('promo_coupons', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->string('code')->unique();
                $table->string('type');                          // fixed | percentage
                $table->decimal('value', 12, 2);
                $table->decimal('min_subtotal', 12, 2)->nullable();
                $table->decimal('max_discount', 12, 2)->nullable(); // cap for percentage
                $table->unsignedInteger('usage_limit')->nullable();
                $table->unsignedInteger('per_customer_limit')->nullable();
                $table->unsignedInteger('used_count')->default(0);
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_to')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('promo_redemptions')) {
            Schema::create('promo_redemptions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('coupon_id');
                $table->uuid('customer_uuid')->nullable()->index();
                $table->uuid('order_uuid')->nullable();
                $table->decimal('amount', 12, 2)->default(0);
                $table->timestamp('at');
                $table->timestamps();

                $table->foreign('coupon_id')->references('id')->on('promo_coupons')->cascadeOnDelete();
                $table->index(['coupon_id', 'customer_uuid']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_redemptions');
        Schema::dropIfExists('promo_coupons');
    }
};
