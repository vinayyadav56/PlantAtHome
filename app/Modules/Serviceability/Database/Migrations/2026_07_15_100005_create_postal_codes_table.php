<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Coverage — the pincode master (one row per unique pincode, dominant
 * district when a pin spans several). Coverage rules resolve to sets of these
 * rows; the (country_id, pincode) unique key is the import upsert key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('postal_codes')) {
            return;
        }

        Schema::create('postal_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('country_id');
            $table->unsignedBigInteger('state_id');
            $table->unsignedBigInteger('district_id');
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('pincode', 10);
            $table->string('office_name', 150)->nullable();
            $table->json('offices')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['country_id', 'pincode']);
            $table->index('pincode');
            $table->index('state_id');
            $table->index('district_id');
            $table->index('city_id');
            $table->index('status');

            $table->foreign('country_id')->references('id')->on('countries')->cascadeOnDelete();
            if (Schema::hasTable('states')) {
                $table->foreign('state_id')->references('id')->on('states')->cascadeOnDelete();
            }
            $table->foreign('district_id')->references('id')->on('districts')->cascadeOnDelete();
            if (Schema::hasTable('cities')) {
                $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postal_codes');
    }
};
