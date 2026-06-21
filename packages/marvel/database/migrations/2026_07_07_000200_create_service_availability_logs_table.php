<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operations Control Center — immutable audit trail for every availability
 * change (global toggle, city×vertical cell, city status, emergency). Mirrors
 * the request_logs pattern (created_at only, no updates).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('service_availability_logs')) {
            return;
        }
        Schema::create('service_availability_logs', function (Blueprint $table) {
            $table->id();
            // global_vertical | city_vertical | city | platform | bulk
            $table->string('entity_type', 32)->index();
            $table->string('entity_id', 128)->nullable(); // slug, "{city_id}:{slug}", city id…
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable()->index();
            $table->text('reason')->nullable();
            $table->string('ip', 64)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_availability_logs');
    }
};
