<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Coverage — tag legacy vendor_service_areas rows written by the
 * coverage bridge (source='coverage_sync') so they can be re-derived / pruned
 * without ever touching a vendor's hand-entered rows (source NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vendor_service_areas') || Schema::hasColumn('vendor_service_areas', 'source')) {
            return;
        }

        Schema::table('vendor_service_areas', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('vendor_service_areas') && Schema::hasColumn('vendor_service_areas', 'source')) {
            Schema::table('vendor_service_areas', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
