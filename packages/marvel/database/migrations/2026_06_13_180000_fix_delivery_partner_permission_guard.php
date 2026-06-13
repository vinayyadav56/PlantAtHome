<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Heal the `delivery_partner` permission + role.
 *
 * The first seed (2026_06_13_120050_*) used a guardless firstOrCreate, which
 * defaults to the `web` guard. The marvel User is `api`-guarded, so a string
 * permission assignment (hasPermissionTo/assignRole) resolves against `api` and
 * threw PermissionDoesNotExist → the delivery-partner create 500'd.
 *
 * Create the perm + role under EVERY guard the existing permissions already use
 * (so it resolves exactly like super_admin), plus `api` explicitly. Idempotent.
 */
return new class extends Migration {
    public function up(): void
    {
        $guards = Permission::query()->distinct()->pluck('guard_name')->filter()->unique();
        if ($guards->isEmpty()) {
            $guards = collect([config('auth.defaults.guard', 'web')]);
        }
        // The marvel User model is api-guarded; make sure that guard is covered.
        $guards = $guards->push('api')->unique()->values();

        foreach ($guards as $guard) {
            $permission = Permission::firstOrCreate(['name' => 'delivery_partner', 'guard_name' => $guard]);
            $role       = Role::firstOrCreate(['name' => 'delivery_partner', 'guard_name' => $guard]);
            if (!$role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Leave the role/permission in place; harmless and keeps existing grants valid.
    }
};
