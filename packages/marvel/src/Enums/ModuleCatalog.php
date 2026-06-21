<?php

namespace Marvel\Enums;

/**
 * Phase C — the data-driven permission catalogue: Module → Submodule → Actions.
 *
 * This is the single source of truth the seeder, the matrix UI and (future)
 * route guards all read from, so adding a module/submodule here makes its
 * permissions appear automatically on the next seed — no other code changes.
 *
 * BACKWARD-COMPAT RULE (load-bearing): the submodule whose key EQUALS its module
 * name is the "default submodule" and emits 2-segment permission strings
 * (`orders.view`), reproducing every legacy flat permission byte-for-byte. Every
 * default submodule keeps the original [view,create,edit,delete] actions (which
 * the old ModulePermission::all() already created for every module) plus any
 * extras, so NO previously-valid permission is invalidated. Non-default
 * submodules emit 3-segment strings (`orders.assignment.assign`).
 */
final class ModuleCatalog
{
    /** Original CRUD actions every default submodule retains (zero-regression). */
    private const CRUD = ['view', 'create', 'edit', 'delete'];

    /**
     * module => [ submodule => [actions], ... ]. The submodule keyed the same as
     * its module is the default (2-segment) submodule.
     */
    public static function catalog(): array
    {
        return [
            'dashboard' => [
                'dashboard' => self::CRUD, // only `view` is used, rest kept for compat
            ],
            'orders' => [
                'orders'     => array_merge(self::CRUD, ['export']),
                'assignment' => ['view', 'assign', 'reassign'],
                'tracking'   => ['view', 'update'],
            ],
            'customers' => [
                'customers' => self::CRUD,        // Customer List
                'addresses' => self::CRUD,
                'support'   => ['view', 'manage'],
            ],
            'vendors' => [
                'vendors'   => self::CRUD,         // Vendor List
                'inventory' => self::CRUD,
                'payments'  => ['view', 'approve', 'manage'],
                'shipments' => ['view', 'create', 'edit', 'cancel'],
            ],
            'products' => [
                'products' => self::CRUD,          // Master Catalog
                'inventory' => ['view', 'create', 'edit'],
                'pricing'   => ['view', 'edit'],
            ],
            'bundles' => [
                'bundles' => self::CRUD,
            ],
            'reports' => [
                'reports'   => array_merge(self::CRUD, ['export']),
                'sales'     => ['view', 'export'],
                'inventory' => ['view', 'export'],
            ],
            'settings' => [
                'settings'      => array_merge(self::CRUD, ['edit']),
                'cities'        => self::CRUD,
                'states'        => self::CRUD,
                'delivery'      => ['view', 'edit'],
                'notifications' => ['view', 'create', 'send'],
            ],
            'employees' => [
                'employees'    => self::CRUD,
                'designations' => self::CRUD,
                'roles'        => self::CRUD,
                'permissions'  => ['view', 'manage'],
            ],
            'kanban' => [
                'kanban' => self::CRUD, // view/edit used; rest kept for compat
            ],
            'operations' => [
                // Service Control Center: view the grid, edit toggles, manage
                // bulk + emergency kill-switches.
                'operations' => ['view', 'edit', 'manage'],
            ],
        ];
    }

    /** Top-level module keys (mirrors ModulePermission::MODULES order). */
    public static function modules(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * The permission name for a (module, submodule, action). The default
     * submodule (submodule === module) collapses to the 2-segment legacy form.
     */
    public static function permissionName(string $module, string $submodule, string $action): string
    {
        return $submodule === $module
            ? "{$module}.{$action}"
            : "{$module}.{$submodule}.{$action}";
    }

    /** Every permission string (legacy 2-segment defaults + new 3-segment). */
    public static function all(): array
    {
        $out = [];
        foreach (self::catalog() as $module => $submodules) {
            foreach ($submodules as $submodule => $actions) {
                foreach ($actions as $action) {
                    $out[] = self::permissionName($module, $submodule, $action);
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** Is $name a known permission (2- or 3-segment) in the catalogue. */
    public static function isValid(string $name): bool
    {
        return in_array($name, self::all(), true);
    }

    /**
     * UI-shaped catalogue: each module with its submodules + the per-submodule
     * actions and pre-computed permission names. Drives the pro matrix.
     */
    public static function forUi(): array
    {
        $modules = [];
        $allActions = [];
        foreach (self::catalog() as $module => $submodules) {
            $subs = [];
            foreach ($submodules as $submodule => $actions) {
                $perms = [];
                foreach ($actions as $action) {
                    $perms[$action] = self::permissionName($module, $submodule, $action);
                    $allActions[$action] = true;
                }
                $subs[] = [
                    'key'         => $submodule,
                    'is_default'  => $submodule === $module,
                    'actions'     => array_values($actions),
                    'permissions' => $perms, // action => permission-name
                ];
            }
            $modules[] = [
                'key'        => $module,
                'submodules' => $subs,
            ];
        }

        return [
            'modules'     => $modules,
            'all_actions' => array_keys($allActions),
        ];
    }
}
