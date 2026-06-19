<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Location System (Phase 2) — fulfilment centres / warehouses per city.
 * Vendors are still the primary fulfilment source; warehouses model the
 * hub-and-spoke option the ops console surfaces. address stored as JSON.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('warehouses')) {
            return;
        }

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('city_id')->nullable()->index();
            $table->string('name');
            $table->json('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
