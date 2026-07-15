<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Coverage — roll the legacy `states` master up to `countries`.
 * Nullable (legacy rows backfilled by GeoMasterSeeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('states') || Schema::hasColumn('states', 'country_id')) {
            return;
        }

        Schema::table('states', function (Blueprint $table) {
            $table->unsignedBigInteger('country_id')->nullable()->after('id');
            $table->index(['country_id', 'is_active']);
        });

        // SQLite can't ALTER TABLE ... ADD CONSTRAINT (test DBs run without FKs).
        if (DB::getDriverName() !== 'sqlite' && Schema::hasTable('countries')) {
            Schema::table('states', function (Blueprint $table) {
                $table->foreign('country_id')->references('id')->on('countries')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('states') && Schema::hasColumn('states', 'country_id')) {
            Schema::table('states', function (Blueprint $table) {
                $table->dropColumn('country_id');
            });
        }
    }
};
