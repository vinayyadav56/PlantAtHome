<?php

namespace Marvel\Enums;

/**
 * F4 — module-based access control. Every admin capability is a
 * `<module>.<action>` permission (Spatie, guard `api`) on top of the legacy
 * coarse role permissions (super_admin/store_owner/staff/customer), which stay
 * intact so existing route groups keep working.
 *
 * The super_admin Gate::before bypass (ShopServiceProvider::givePermissionToSuperAdmin)
 * means a super-admin passes every `permission:<module>.<action>` check without
 * being granted the rows explicitly.
 */
final class ModulePermission
{
    /** Admin modules the UI + API gate on. */
    public const MODULES = [
        'dashboard',
        'orders',
        'customers',
        'vendors',
        'products',
        'bundles',
        'reports',
        'settings',
        'employees',
        'kanban',
    ];

    /** Actions per module. */
    public const ACTIONS = ['view', 'create', 'edit', 'delete'];

    /** Legacy coarse permissions that grant admin-panel sign-in + existing routes. */
    public const GUARD = 'api';

    /**
     * Every permission name. Phase C — delegated to the data-driven ModuleCatalog
     * so it returns the legacy 2-segment module.action perms (default submodules,
     * unchanged) PLUS the new 3-segment submodule perms. The seeder reads this, so
     * new catalogue entries auto-create their permission rows on the next deploy.
     */
    public static function all(): array
    {
        return ModuleCatalog::all();
    }

    /** "<module>.<action>" names for a single module (defaults to every action). */
    public static function forModule(string $module, array $actions = self::ACTIONS): array
    {
        return array_map(fn ($action) => "{$module}.{$action}", $actions);
    }

    /** "<module>.view" for every module — the read-only (Viewer) set. */
    public static function allView(): array
    {
        return array_map(fn ($module) => "{$module}.view", self::MODULES);
    }

    /** Is $name a valid permission string (2- or 3-segment, via the catalogue). */
    public static function isValid(string $name): bool
    {
        return ModuleCatalog::isValid($name);
    }

    /**
     * The roles this system manages and the BASE coarse permission(s) each needs
     * so it can sign into the admin panel (the login gate requires one of
     * super_admin / store_owner / staff) and reach the legacy route groups.
     */
    public static function basePermissions(): array
    {
        return [
            'super_admin' => ['super_admin', 'store_owner', 'customer'],
            // Internal admin is a PLATFORM employee, not a vendor: it must carry the
            // 'staff' coarse login-gate (so the admin app routes it into the filtered
            // admin panel via isAdminPanelUser), NOT 'store_owner' (which made admin
            // employees render the vendor dashboard). roleMatrix still grants it full
            // module access, so capability is unchanged — only the coarse perm changes.
            'admin'       => ['staff', 'customer'],
            'manager'     => ['staff', 'customer'],
            'staff'       => ['staff', 'customer'],
            'viewer'      => ['staff', 'customer'],
            // Existing vendor role — base unchanged; module perms layered on.
            'store_owner' => ['store_owner', 'customer'],
        ];
    }

    /**
     * Default module-permission set per role. Seeded ADDITIVELY (givePermissionTo)
     * so manual grants and existing behaviour are preserved; the matrix UI can
     * later refine any role.
     */
    public static function roleMatrix(): array
    {
        $all = self::all();

        return [
            'super_admin' => $all,
            'admin'       => $all,
            'manager'     => array_merge(
                self::forModule('dashboard', ['view']),
                self::forModule('orders', ['view', 'create', 'edit']),
                self::forModule('customers', ['view', 'edit']),
                self::forModule('vendors', ['view']),
                self::forModule('products', ['view', 'create', 'edit']),
                self::forModule('bundles', ['view', 'create', 'edit']),
                self::forModule('reports', ['view']),
                self::forModule('kanban', ['view', 'edit']),
            ),
            'staff' => array_merge(
                self::forModule('dashboard', ['view']),
                self::forModule('orders', ['view', 'edit']),
                self::forModule('customers', ['view']),
                self::forModule('products', ['view']),
                self::forModule('bundles', ['view']),
                self::forModule('kanban', ['view', 'edit']),
            ),
            'viewer' => self::allView(),
            'store_owner' => array_merge(
                self::forModule('dashboard', ['view']),
                self::forModule('orders', ['view', 'edit']),
                self::forModule('products', ['view', 'create', 'edit', 'delete']),
                self::forModule('bundles', ['view', 'create', 'edit']),
                self::forModule('vendors', ['view']),
                self::forModule('reports', ['view']),
                self::forModule('kanban', ['view', 'edit']),
            ),
        ];
    }

    /** Roles that must never be edited away from full access (lockout guard). */
    public static function protectedRoles(): array
    {
        return ['super_admin'];
    }

    /** Roles this system manages in the matrix UI. */
    public static function managedRoles(): array
    {
        return array_keys(self::roleMatrix());
    }
}
