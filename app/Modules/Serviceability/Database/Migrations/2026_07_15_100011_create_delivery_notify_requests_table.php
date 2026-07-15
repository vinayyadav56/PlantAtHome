<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Coverage — "notify me when you deliver here": captured from the
 * storefront pincode check when no vendor covers a pin. Demand signal for
 * expansion; status pending → notified.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delivery_notify_requests')) {
            return;
        }

        Schema::create('delivery_notify_requests', function (Blueprint $table) {
            $table->id();
            $table->string('pincode', 10)->index();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_notify_requests');
    }
};
