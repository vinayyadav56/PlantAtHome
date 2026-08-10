<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order activity log — one row per REAL transition (created, status/payment change,
 * assignment, booking, shipment status). Written via OrderEvent::record(), which
 * swallows every failure: an audit row must never break checkout or a webhook.
 * Read by the admin order-detail Activity Timeline (GET orders/{id}/events).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_events')) {
            return;
        }
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('type', 64);
            $table->string('label')->nullable();
            $table->string('actor_type', 32)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
    }
};
