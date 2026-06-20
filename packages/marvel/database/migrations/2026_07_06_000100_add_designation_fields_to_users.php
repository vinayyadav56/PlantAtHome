<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B — employee/org fields on users. All nullable + additive so existing
 * users and the customer/vendor flows are untouched.
 *
 * permission_source: 'designation' ⇒ effective perms = designation defaults;
 *                    'custom'      ⇒ effective = (defaults ∪ grant) − revoke.
 * permission_overrides: {"grant":[...], "revoke":[...]} of module-perm strings.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'designation_id')) {
                $table->unsignedBigInteger('designation_id')->nullable()->after('shop_id')->index();
            }
            if (!Schema::hasColumn('users', 'reporting_manager_id')) {
                $table->unsignedBigInteger('reporting_manager_id')->nullable()->after('designation_id')->index();
            }
            if (!Schema::hasColumn('users', 'department')) {
                $table->string('department')->nullable()->after('reporting_manager_id');
            }
            if (!Schema::hasColumn('users', 'city')) {
                $table->string('city')->nullable()->after('department');
            }
            if (!Schema::hasColumn('users', 'state')) {
                $table->string('state')->nullable()->after('city');
            }
            if (!Schema::hasColumn('users', 'permission_source')) {
                $table->string('permission_source')->default('designation')->after('state');
            }
            if (!Schema::hasColumn('users', 'permission_overrides')) {
                $table->json('permission_overrides')->nullable()->after('permission_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'designation_id',
                'reporting_manager_id',
                'department',
                'city',
                'state',
                'permission_source',
                'permission_overrides',
            ] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
