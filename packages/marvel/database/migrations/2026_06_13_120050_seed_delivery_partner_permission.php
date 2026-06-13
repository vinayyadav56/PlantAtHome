<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Register the `delivery_partner` permission + role (mirrors marvel's
 * InstallCommand which firstOrCreate's super_admin/store_owner/staff/customer
 * on the default guard). Idempotent — safe to re-run.
 */
return new class extends Migration {
    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => 'delivery_partner']);
        Role::firstOrCreate(['name' => 'delivery_partner'])->syncPermissions([$permission]);
    }

    public function down(): void
    {
        Role::where('name', 'delivery_partner')->delete();
        Permission::where('name', 'delivery_partner')->delete();
    }
};
