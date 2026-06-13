<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery partners (riders) onboarded by PlantAtHome. A DP is a `user` (login)
 * plus this KYC + location + commission profile. A vendor can also BE a delivery
 * partner (is_vendor_cum_dp + shop_id), in which case both roles share one login.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('delivery_partners')) {
            return;
        }
        Schema::create('delivery_partners', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();   // login
            $table->unsignedBigInteger('shop_id')->nullable()->index();   // linked vendor (cum-DP)
            $table->boolean('is_vendor_cum_dp')->default(false);

            $table->string('full_name');
            $table->string('mobile', 20)->nullable()->index();
            $table->string('email')->nullable();

            // KYC (numbers are encrypted at the model layer; docs are media JSON)
            $table->text('aadhaar_number')->nullable();
            $table->text('pan_number')->nullable();
            $table->json('aadhaar_doc')->nullable();
            $table->json('pan_doc')->nullable();
            $table->json('live_photo')->nullable();

            $table->string('vehicle_type')->nullable();

            // address + geocoded location
            $table->json('address')->nullable();
            $table->json('location')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            // status
            $table->string('status')->default('pending');   // pending | approved | suspended
            $table->boolean('is_active')->default(true);

            // standard delivery commission
            $table->string('commission_basis')->default('per_order');   // per_order | per_plant
            $table->string('commission_type')->default('fixed');        // fixed | percentage
            $table->decimal('commission_value', 12, 2)->default(0);

            // courier-handled-delivery commission (when a DP runs a courier leg)
            $table->string('courier_commission_basis')->default('per_order');
            $table->string('courier_commission_type')->default('fixed');
            $table->decimal('courier_commission_value', 12, 2)->default(0);

            $table->json('payment_info')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['lat', 'lng']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_partners');
    }
};
