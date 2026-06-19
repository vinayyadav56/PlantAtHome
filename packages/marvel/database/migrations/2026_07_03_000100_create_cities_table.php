<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Location System (Phase 2) — the canonical city table + the City
 * Activation Engine. `status` drives serviceability platform-wide:
 *   active      → normal
 *   paused      → temporarily not accepting orders (checkout blocked)
 *   maintenance → accepting but flagged (delays expected)
 *   disabled    → fully off (not serviceable)
 * `settings` JSON holds per-city ops/delivery config (delivery_charge_base,
 * min_order_value, cod, eta range, ops manager, notes) so no side tables sprawl.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cities')) {
            return;
        }

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->unsignedBigInteger('state_id')->nullable()->index();
            $table->string('state_name')->nullable(); // denormalized for quick reads
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('status', 16)->default('active')->index(); // active|paused|maintenance|disabled
            $table->boolean('is_serviceable')->default(true)->index();
            $table->json('settings')->nullable();
            $table->unsignedInteger('display_order')->default(0)->index();
            $table->timestamps();

            $table->unique(['name', 'state_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
