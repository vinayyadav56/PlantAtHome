<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side per-user cart so a logged-in customer's cart follows their account
 * across devices (Android / iOS / web). One row per user; `items` is the canonical
 * minimal cart (product_id + variation_option_id + quantity), hydrated to full
 * products on read.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('carts')) {
            return;
        }
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->json('items')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
