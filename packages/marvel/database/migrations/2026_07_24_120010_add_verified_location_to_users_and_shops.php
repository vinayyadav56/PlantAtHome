<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verified GPS location for customers (users) and vendors (shops), captured
 * via the Location Capture Email flow. Deliberately verified_*-prefixed:
 * users.city/state are EMPLOYEE HR/RBAC fields and preferred_city/last_lat
 * are storefront shopping prefs — none of those may be overwritten here.
 * All additive + nullable + guarded.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'verified_latitude', 'verified_longitude', 'verified_address', 'verified_city',
        'verified_state', 'verified_country', 'verified_postal_code', 'verified_place_id',
        'location_verified', 'location_verified_at',
    ];

    public function up(): void
    {
        foreach (['users', 'shops'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'verified_latitude')) {
                    $table->decimal('verified_latitude', 10, 7)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'verified_longitude')) {
                    $table->decimal('verified_longitude', 10, 7)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'verified_address')) {
                    $table->string('verified_address')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'verified_city')) {
                    $table->string('verified_city')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'verified_state')) {
                    $table->string('verified_state')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'verified_country')) {
                    $table->string('verified_country')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'verified_postal_code')) {
                    $table->string('verified_postal_code', 32)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'verified_place_id')) {
                    $table->string('verified_place_id')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'location_verified')) {
                    $table->boolean('location_verified')->default(false);
                }
                if (!Schema::hasColumn($tableName, 'location_verified_at')) {
                    $table->timestamp('location_verified_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'shops'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (self::COLUMNS as $col) {
                    if (Schema::hasColumn($tableName, $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
