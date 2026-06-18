<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-editable courier partner config. Holds the per-partner enable flag + non-secret settings
 * (base_url, vehicle_type_id, matter, timeout) and the ENCRYPTED credentials (token / api_token /
 * email+password / webhook_token / service url+api_key). Secrets live here (encrypted at rest via
 * the model's `encrypted:array` cast), NEVER in settings.options (which is returned publicly).
 * CourierService overrides config('services.{partner}.*') from these rows (DB-first, env fallback).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('courier_partner_configs')) {
            return;
        }
        Schema::create('courier_partner_configs', function (Blueprint $table) {
            $table->id();
            $table->string('partner_code')->unique(); // borzo | shiprocket | shipping_service
            $table->boolean('enabled')->default(false);
            $table->json('settings')->nullable();      // non-secret config
            $table->text('credentials')->nullable();   // encrypted JSON of secret fields
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_partner_configs');
    }
};
