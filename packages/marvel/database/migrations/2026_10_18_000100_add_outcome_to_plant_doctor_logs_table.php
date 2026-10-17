<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The staged Plant Doctor service reports an outcome (plant_detected / non_plant / uncertain /
 * poor_quality / mixed_content), the gate rule that decided it, the identification confidence
 * level and the time it took. Rows written before this migration keep NULLs; `is_plant` still
 * says whether they were shown a diagnosis.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('plant_doctor_logs')) {
            return;
        }
        Schema::table('plant_doctor_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('plant_doctor_logs', 'outcome')) {
                $table->string('outcome', 24)->nullable()->index();
            }
            if (!Schema::hasColumn('plant_doctor_logs', 'gate_rule')) {
                $table->string('gate_rule', 48)->nullable();
            }
            if (!Schema::hasColumn('plant_doctor_logs', 'confidence_level')) {
                $table->string('confidence_level', 8)->nullable();
            }
            if (!Schema::hasColumn('plant_doctor_logs', 'duration_ms')) {
                $table->unsignedInteger('duration_ms')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('plant_doctor_logs')) {
            return;
        }
        Schema::table('plant_doctor_logs', function (Blueprint $table) {
            foreach (['outcome', 'gate_rule', 'confidence_level', 'duration_ms'] as $col) {
                if (Schema::hasColumn('plant_doctor_logs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
