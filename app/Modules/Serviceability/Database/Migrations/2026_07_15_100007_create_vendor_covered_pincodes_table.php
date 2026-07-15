<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Coverage — the flattened projection of vendor_coverage_rules: one
 * row per (vendor, deliverable pincode) with the winning source. This is the
 * hot lookup ("who delivers to 122001?"), so: no FKs, no timestamps — it is
 * rewritten wholesale by CoverageProjector on every sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_covered_pincodes')) {
            return;
        }

        Schema::create('vendor_covered_pincodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('pincode', 10);
            $table->string('source', 10);
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();

            $table->unique(['shop_id', 'pincode']);
            $table->index('pincode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_covered_pincodes');
    }
};
