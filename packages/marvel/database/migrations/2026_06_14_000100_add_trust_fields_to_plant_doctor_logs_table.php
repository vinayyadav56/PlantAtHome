<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('plant_doctor_logs')) {
            return;
        }
        Schema::table('plant_doctor_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('plant_doctor_logs', 'is_plant')) {
                // false = the image was rejected as not-a-plant / unusable (no diagnosis shown).
                $table->boolean('is_plant')->default(true)->index();
            }
            if (!Schema::hasColumn('plant_doctor_logs', 'identified_species')) {
                $table->string('identified_species')->nullable();
            }
            if (!Schema::hasColumn('plant_doctor_logs', 'id_confidence')) {
                // Species-identification confidence (0-1) the AI reported.
                $table->decimal('id_confidence', 4, 3)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('plant_doctor_logs')) {
            return;
        }
        Schema::table('plant_doctor_logs', function (Blueprint $table) {
            foreach (['is_plant', 'identified_species', 'id_confidence'] as $col) {
                if (Schema::hasColumn('plant_doctor_logs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
