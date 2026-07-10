<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity RBAC: roles and permissions with a role↔permission pivot. Roles are
 * a small fixed set (Section 13); permissions are the fine-grained capabilities
 * a role grants. Kept in the Identity module's own tables (identity_*) so the
 * v2 context owns its data independently of the legacy marvel/spatie tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('identity_roles')) {
            Schema::create('identity_roles', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name')->unique();   // customer, nursery_staff, …
                $table->string('label');
                $table->unsignedSmallInteger('level')->default(0); // hierarchy rank
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('identity_permissions')) {
            Schema::create('identity_permissions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name')->unique();   // catalog.manage, orders.view_all, …
                $table->string('label');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('identity_permission_role')) {
            Schema::create('identity_permission_role', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
                $table->foreign('permission_id')->references('id')->on('identity_permissions')->cascadeOnDelete();
                $table->foreign('role_id')->references('id')->on('identity_roles')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_permission_role');
        Schema::dropIfExists('identity_permissions');
        Schema::dropIfExists('identity_roles');
    }
};
