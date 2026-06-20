<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Marvel\Enums\ModulePermission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase A — make the internal `admin` role a PLATFORM employee, not a vendor.
 *
 * The seeder historically granted the `admin` role a base of
 * ['store_owner','customer'], so every admin employee carried the `store_owner`
 * coarse permission and the admin app rendered them the vendor dashboard. We've
 * flipped the base to ['staff','customer'] in ModulePermission::basePermissions(),
 * but RolePermissionSeeder is purely ADDITIVE (givePermissionTo, never revokes),
 * so it cannot remove the stale `store_owner` grant already on the role.
 *
 * This migration does the one subtractive step at the ROLE level (so it
 * propagates to every admin-role user via Spatie inheritance, no per-user loop):
 *   - revoke `store_owner` from the internal `admin` role
 *   - ensure `staff` is on it (login gate; the seeder also adds this)
 *
 * It is SAFE for a user who is both an internal admin AND a real vendor: such a
 * user also holds the separate `store_owner` ROLE, which still grants the
 * permission. We only touch the `admin` role, never the `store_owner` role or
 * any direct user grant. Idempotent + re-run-safe.
 */
return new class extends Migration {
    public function up(): void
    {
        $guard = ModulePermission::GUARD;

        /** @var \Spatie\Permission\Models\Role|null $admin */
        $admin = Role::where('name', 'admin')->where('guard_name', $guard)->first();
        if (!$admin) {
            Log::info('fix_admin_base_perm: no internal `admin` role — nothing to do.');
            return;
        }

        $hadStoreOwner = $admin->hasPermissionTo('store_owner');
        if ($hadStoreOwner) {
            $admin->revokePermissionTo('store_owner');
        }
        if (!$admin->hasPermissionTo('staff')) {
            $admin->givePermissionTo('staff');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Log::info('fix_admin_base_perm: admin role normalised', [
            'revoked_store_owner' => $hadStoreOwner,
            'admin_user_count'    => $admin->users()->count(),
        ]);
    }

    public function down(): void
    {
        // Intentionally irreversible: restoring the store_owner coarse grant would
        // re-introduce the vendor-dashboard bug. The seeder re-establishes the
        // correct (staff-based) admin role on the next run.
    }
};
