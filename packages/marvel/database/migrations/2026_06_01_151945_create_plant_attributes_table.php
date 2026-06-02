<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the plant_attributes table — a one-to-one botanical detail extension
 * for products of type 'plants'. All columns are nullable so the table can be
 * populated incrementally (seeder fills what the CSV provides; gaps filled later).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plant_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->unique();
            $table->foreign('product_id')
                ->references('id')->on('products')
                ->cascadeOnDelete();

            // Taxonomy / identity
            $table->string('scientific_name', 255)->nullable()->index();
            $table->string('hindi_name', 255)->nullable();

            // Placement
            $table->string('indoor_outdoor', 50)->nullable();   // 'Indoor', 'Outdoor', 'Indoor/Outdoor'

            // Growth / physical
            $table->string('height_range', 100)->nullable();    // e.g. '1 m - 20 m'
            $table->string('life_span', 100)->nullable();       // e.g. 'Perennial', '10-25 Years'
            $table->string('growth_rate', 50)->nullable();      // 'Fast', 'Moderate', 'Slow'

            // Care requirements
            $table->string('temperature_range', 50)->nullable(); // e.g. '18-30' (°C)
            $table->string('sunlight', 100)->nullable();         // e.g. 'Bright Indirect Light'
            $table->string('water_requirement', 100)->nullable(); // e.g. 'Moderate', 'Low'
            $table->string('flowering_season', 100)->nullable(); // e.g. 'Year-round', 'Summer'

            // Benefits / safety flags
            $table->text('benefits')->nullable();
            $table->text('medicinal_uses')->nullable();
            $table->tinyInteger('air_purifying')->nullable();   // 1 = Yes, 0 = No, null = unknown
            $table->tinyInteger('pet_friendly')->nullable();    // 1 = Yes, 0 = No, null = unknown

            // Origin
            $table->string('native_region', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plant_attributes');
    }
};
