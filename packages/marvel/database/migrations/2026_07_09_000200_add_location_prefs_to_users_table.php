<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer location preferences — remembered city + last detected GPS so the
 * storefront can greet a returning/logged-in shopper with their city and detect
 * a significant change on login. Distinct from the existing org/employee
 * `users.city`/`users.state` (those are HR/RBAC fields). All additive + nullable.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'preferred_city')) {
                $table->string('preferred_city')->nullable()->after('state');
            }
            if (!Schema::hasColumn('users', 'last_detected_city')) {
                $table->string('last_detected_city')->nullable()->after('preferred_city');
            }
            if (!Schema::hasColumn('users', 'last_lat')) {
                $table->decimal('last_lat', 10, 7)->nullable()->after('last_detected_city');
            }
            if (!Schema::hasColumn('users', 'last_lng')) {
                $table->decimal('last_lng', 10, 7)->nullable()->after('last_lat');
            }
            if (!Schema::hasColumn('users', 'location_updated_at')) {
                $table->timestamp('location_updated_at')->nullable()->after('last_lng');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            foreach (['preferred_city', 'last_detected_city', 'last_lat', 'last_lng', 'location_updated_at'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
