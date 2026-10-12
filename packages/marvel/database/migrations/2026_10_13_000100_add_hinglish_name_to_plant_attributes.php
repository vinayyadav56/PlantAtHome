<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The plant's Hindi name in Roman letters ("Tulsi"), next to hindi_name in
 * Devanagari (तुलसी). Owner 2026-10-12: show and search both.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plant_attributes') && !Schema::hasColumn('plant_attributes', 'hinglish_name')) {
            Schema::table('plant_attributes', function (Blueprint $table) {
                $table->string('hinglish_name')->nullable()->after('hindi_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('plant_attributes', 'hinglish_name')) {
            Schema::table('plant_attributes', function (Blueprint $table) {
                $table->dropColumn('hinglish_name');
            });
        }
    }
};
