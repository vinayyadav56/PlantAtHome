<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Enums\ModulePermission;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * F4 — seeds module-based permissions + the employee roles.
 *
 * Idempotent and ADDITIVE: it only creates missing permissions and GRANTS the
 * canonical set per role (never revokes), so re-running on every Railway deploy
 * is safe and existing role behaviour / manual grants are preserved. All rows
 * use guard `api` to match the existing Spatie data + the User model guard.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $guard = ModulePermission::GUARD;

        // 1. Legacy coarse permissions (idempotent — they normally already exist).
        foreach (['super_admin', 'store_owner', 'staff', 'customer'] as $name) {
            Permission::findOrCreate($name, $guard);
        }

        // 2. Every module.action permission.
        foreach (ModulePermission::all() as $name) {
            Permission::findOrCreate($name, $guard);
        }

        // 3. Roles = base coarse perms + module perms (additive).
        $base = ModulePermission::basePermissions();
        foreach (ModulePermission::roleMatrix() as $roleName => $modulePerms) {
            $role = Role::findOrCreate($roleName, $guard);
            $full = array_values(array_unique(array_merge($base[$roleName] ?? [], $modulePerms)));
            $role->givePermissionTo($full);
        }

        // 4. Flush the Spatie cache so new grants take effect immediately.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
