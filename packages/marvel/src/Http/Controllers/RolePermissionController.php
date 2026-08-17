<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Marvel\Enums\ModuleCatalog;
use Marvel\Enums\ModulePermission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * F4 — read + edit the role × module-permission matrix. Super-admin only
 * (routes are in the super_admin group). Base coarse permissions are always
 * preserved on save so a role never loses admin sign-in / legacy route access,
 * and the `super_admin` role is never editable (lockout guard).
 */
class RolePermissionController extends CoreController
{
    /**
     * The matrix: every managed role with its currently-granted module
     * permissions, plus the module/action catalogue the UI renders from.
     */
    public function roles(Request $request)
    {
        $guard = ModulePermission::GUARD;
        // Only the true system roles are shown in the matrix; employee access is
        // managed via Designations. Legacy presets still seed but stay hidden.
        $managed = ModulePermission::systemRoles();

        $roles = Role::where('guard_name', $guard)
            ->whereIn('name', $managed)
            ->with('permissions:id,name')
            ->get()
            ->map(function (Role $role) {
                $names = $role->permissions->pluck('name')->all();

                return [
                    'id'          => $role->id,
                    'name'        => $role->name,
                    // Only the module.action perms (UI matrix); coarse perms hidden.
                    'permissions' => array_values(array_filter(
                        $names,
                        fn ($p) => ModulePermission::isValid($p)
                    )),
                    'editable'    => !in_array($role->name, ModulePermission::protectedRoles(), true),
                ];
            });

        return [
            'roles'   => $roles,
            'modules' => ModulePermission::modules(),
            'actions' => ModulePermission::ACTIONS,
            // Phase C — submodule catalogue for the professional matrix UI.
            'catalog' => ModuleCatalog::forUi(),
        ];
    }

    /** Phase C — the module → submodule → action catalogue (standalone). */
    public function catalog(Request $request)
    {
        return ModuleCatalog::forUi();
    }

    /** Flat catalogue of every module permission (grouped by module). */
    public function permissions(Request $request)
    {
        $grouped = [];
        foreach (ModulePermission::modules() as $module) {
            $grouped[$module] = ModulePermission::forModule($module);
        }

        return [
            'modules'     => ModulePermission::modules(),
            'actions'     => ModulePermission::ACTIONS,
            'permissions' => $grouped,
        ];
    }

    /**
     * Replace a role's MODULE permissions (coarse base perms are re-applied so
     * the role keeps admin access). Rejects unknown perms + protected roles.
     */
    public function updateRolePermissions(Request $request, $id)
    {
        $role = Role::where('guard_name', ModulePermission::GUARD)->findOrFail($id);

        if (in_array($role->name, ModulePermission::protectedRoles(), true)) {
            throw ValidationException::withMessages([
                'role' => ["The {$role->name} role cannot be modified."],
            ]);
        }
        if (!in_array($role->name, ModulePermission::managedRoles(), true)) {
            throw ValidationException::withMessages([
                'role' => ["The {$role->name} role is not managed here."],
            ]);
        }

        $request->validate([
            'permissions'   => 'present|array',
            'permissions.*' => 'string',
        ]);

        // Keep only valid module permissions from the payload (defence in depth).
        $modulePerms = array_values(array_filter(
            (array) $request->input('permissions', []),
            fn ($p) => is_string($p) && ModulePermission::isValid($p)
        ));

        // Always re-apply the role's base coarse permissions so it keeps sign-in
        // + legacy route access regardless of what the matrix submitted.
        $base = ModulePermission::basePermissions()[$role->name] ?? [];
        $full = array_values(array_unique(array_merge($base, $modulePerms)));

        $role->syncPermissions($full);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [
            'id'          => $role->id,
            'name'        => $role->name,
            'permissions' => $modulePerms,
        ];
    }
}
