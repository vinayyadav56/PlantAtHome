<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serviceability (Section 4.7): cities, pincodes, and per-vendor coverage areas
 * (a delivery radius around a centre point). Drives city-aware catalog +
 * configuration and Porter-style local-delivery eligibility. Prefixed svc_*
 * (legacy marvel owns `cities`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('svc_cities')) {
            Schema::create('svc_cities', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->string('name');
                $table->string('state')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('is_active');
            });
        }

        if (! Schema::hasTable('svc_pincodes')) {
            Schema::create('svc_pincodes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('city_id');
                $table->string('code', 12);
                $table->timestamps();

                $table->foreign('city_id')->references('id')->on('svc_cities')->cascadeOnDelete();
                $table->unique(['city_id', 'code']);
                $table->index('code');
            });
        }

        if (! Schema::hasTable('svc_vendor_areas')) {
            Schema::create('svc_vendor_areas', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->uuid('nursery_id');
                $table->unsignedBigInteger('city_id');
                $table->decimal('delivery_radius_km', 6, 2)->default(0);
                $table->decimal('center_lat', 10, 7)->nullable();
                $table->decimal('center_lng', 10, 7)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('city_id')->references('id')->on('svc_cities')->cascadeOnDelete();
                $table->unique(['nursery_id', 'city_id']);
                $table->index(['city_id', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('svc_vendor_areas');
        Schema::dropIfExists('svc_pincodes');
        Schema::dropIfExists('svc_cities');
    }
};
