<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery Coverage — roll the legacy `cities` master up to `districts`.
 * Nullable: only best-effort name matches are backfilled (GeoMasterSeeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cities') || Schema::hasColumn('cities', 'district_id')) {
            return;
        }

        Schema::table('cities', function (Blueprint $table) {
            $table->unsignedBigInteger('district_id')->nullable()->index()->after('state_id');
        });

        // SQLite can't ALTER TABLE ... ADD CONSTRAINT (test DBs run without FKs).
        if (DB::getDriverName() !== 'sqlite' && Schema::hasTable('districts')) {
            Schema::table('cities', function (Blueprint $table) {
                $table->foreign('district_id')->references('id')->on('districts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cities') && Schema::hasColumn('cities', 'district_id')) {
            Schema::table('cities', function (Blueprint $table) {
                $table->dropColumn('district_id');
            });
        }
    }
};
