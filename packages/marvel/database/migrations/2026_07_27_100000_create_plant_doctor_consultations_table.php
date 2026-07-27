<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side Plant Doctor history — one row per saved diagnosis for a
 * logged-in customer (replaces the localStorage-only stopgap). The `diagnosis`
 * column stores the full diagnose-response JSON; `thumb` is a small data-URI
 * jpeg for the history rail. Rows are customer-owned and capped per user.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('plant_doctor_consultations')) {
            return;
        }
        Schema::create('plant_doctor_consultations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('plant_name')->nullable();
            $table->mediumText('thumb')->nullable();      // data-URI jpeg thumbnail (≤ ~50KB)
            $table->mediumText('diagnosis');              // JSON payload from the diagnose response
            $table->unsignedTinyInteger('health_score')->nullable(); // 0–100
            $table->string('worst_severity', 16)->nullable();        // low | medium | high | critical
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plant_doctor_consultations');
    }
};
