<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financial completeness F4 — vendor GSTIN, needed for the per-vendor GST report.
 * Additive + nullable; existing shops are unaffected.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('shops')) {
            return;
        }
        Schema::table('shops', function (Blueprint $table) {
            if (!Schema::hasColumn('shops', 'gst_number')) {
                $table->string('gst_number', 32)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shops')) {
            return;
        }
        Schema::table('shops', function (Blueprint $table) {
            if (Schema::hasColumn('shops', 'gst_number')) {
                $table->dropColumn('gst_number');
            }
        });
    }
};
