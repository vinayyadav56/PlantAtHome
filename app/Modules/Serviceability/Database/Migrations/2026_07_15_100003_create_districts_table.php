<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Coverage — the administrative tier between a state and its cities /
 * pincodes (638 Indian districts). Coverage rules can target a whole district.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('districts')) {
            return;
        }

        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('state_id');
            $table->string('name', 120);
            $table->string('code', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['state_id', 'name']);
            $table->index('name');
            if (Schema::hasTable('states')) {
                $table->foreign('state_id')->references('id')->on('states')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('districts');
    }
};
