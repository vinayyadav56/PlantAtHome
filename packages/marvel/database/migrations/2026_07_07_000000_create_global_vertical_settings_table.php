<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operations Control Center — the GLOBAL master switch per business vertical
 * (Tier 1 of the availability resolver). One row per vertical slug (auto-seeded
 * from the Type table + the 2 service verticals), plus a reserved `__platform__`
 * row for the emergency kill-switch / maintenance mode. Absence ⇒ fail open.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('global_vertical_settings')) {
            return;
        }
        Schema::create('global_vertical_settings', function (Blueprint $table) {
            $table->id();
            $table->string('vertical_slug', 64)->unique();
            $table->boolean('is_active')->default(true)->index();
            // active | maintenance | disabled | coming_soon  (+ platform flags
            // for the reserved __platform__ row: e.g. stop_orders, stop_platform).
            $table->string('status', 24)->default('active');
            $table->text('maintenance_message')->nullable();
            $table->json('settings')->nullable(); // platform flags / future config
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_vertical_settings');
    }
};
