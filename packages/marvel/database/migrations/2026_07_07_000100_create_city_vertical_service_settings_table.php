<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operations Control Center — the CITY × VERTICAL matrix (Tier 3 of the resolver).
 * Stores only OVERRIDES: absence of a row ⇒ the vertical inherits the city +
 * global status (fail open). The city master switch itself is the existing
 * `cities.status` (City Activation Engine) — NOT duplicated here.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('city_vertical_service_settings')) {
            return;
        }
        Schema::create('city_vertical_service_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('city_id')->index();
            $table->string('vertical_slug', 64)->index();
            // active | inactive | maintenance | coming_soon
            $table->string('status', 24)->default('active');
            $table->text('maintenance_message')->nullable();
            // Schema-only for now (time-based availability is a future expansion).
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['city_id', 'vertical_slug']);
            $table->foreign('city_id')->references('id')->on('cities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_vertical_service_settings');
    }
};
