<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('delivery_pincodes')) {
            return;
        }
        // Allow-list of serviceable delivery pincodes. A row that is_active = true
        // means we deliver there; any pincode NOT present (or is_active = false) is
        // treated as not serviceable (hard-blocked at checkout).
        Schema::create('delivery_pincodes', function (Blueprint $table) {
            $table->id();
            $table->string('pincode', 12)->unique();
            $table->string('area')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('cod_enabled')->default(true);
            $table->unsignedSmallInteger('eta_days')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_pincodes');
    }
};
