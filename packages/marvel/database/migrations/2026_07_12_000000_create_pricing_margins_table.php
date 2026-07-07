<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PlantAtHome selling-margin matrix. The customer selling price for a product in a
 * city is MAX(vendor rate among vendors covering the city) × (1 + margin%). The
 * margin resolves most-specific-first: (city + vertical) → city → vertical → global
 * (city NULL + type_id NULL). Managed by super-admin (PricingMarginController).
 *
 * `city` is stored normalized (lowercase, canonical alias — see
 * AvailabilityService::normalizeCityKey) so lookups match the availability
 * projection. NOTE: MySQL unique indexes allow multiple NULLs, so uniqueness of the
 * (city, type_id) pair is ALSO enforced by updateOrCreate in the controller.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pricing_margins')) {
            return;
        }
        Schema::create('pricing_margins', function (Blueprint $table) {
            $table->id();
            $table->string('city')->nullable()->index();               // NULL = all cities
            $table->unsignedBigInteger('type_id')->nullable()->index(); // vertical; NULL = all
            $table->decimal('margin_percent', 8, 4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['city', 'type_id'], 'pm_city_type_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_margins');
    }
};
