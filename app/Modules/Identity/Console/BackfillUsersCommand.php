<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Domain\RoleName;
use App\Shared\Infrastructure\Backfill\BackfillCommand;
use App\Shared\Infrastructure\Backfill\LegacyUuid;
use Illuminate\Support\Facades\DB;

/**
 * v2:backfill-users — legacy `users` (marvel, spatie-permission roles) into
 * `identity_users`. Bcrypt hashes copy verbatim, so migrated users keep their
 * passwords. Role mapping (legacy permission name -> v2 role):
 *   super_admin              -> super_admin
 *   staff (admin employees)  -> admin
 *   store_owner + shop_id    -> nursery_owner (nursery_id = LegacyUuid shops:<shop_id>)
 *   otherwise                -> customer
 */
class BackfillUsersCommand extends BackfillCommand
{
    protected $signature = 'v2:backfill-users {--dry-run} {--verify} {--since=} {--since-cursor}';

    protected $description = 'Backfill legacy users into identity_users (idempotent upsert by legacy_id)';

    /** @var array<string,int>|null role name -> identity_roles.id */
    private ?array $roleIds = null;

    /** @var array<int,string>|null legacy user id -> legacy permission names */
    private ?array $permissionNames = null;

    /** @var array<int,int>|null legacy owner user id -> first owned shop id */
    private ?array $ownedShopIds = null;

    protected function steps(): array
    {
        return [[
            'resource' => 'users',
            'source' => 'users',
            'target' => 'identity_users',
            'map' => function (object $row): ?array {
                $role = $this->roleForLegacyUser($row);

                // Owners are linked via shops.owner_id (users.shop_id is only
                // set for staff), so scope owners to the shop they own.
                $scopeShopId = match ($role) {
                    RoleName::NURSERY_OWNER => $this->ownedShopIds()[$row->id] ?? ($row->shop_id ?: null),
                    RoleName::NURSERY_STAFF => $row->shop_id ?: null,
                    default => null,
                };

                return [
                    'uuid' => LegacyUuid::for('users', $row->id),
                    'legacy_id' => $row->id,
                    'name' => $row->name ?? trim(($row->email ?? 'user').''),
                    'email' => $row->email,
                    'password' => $row->password,
                    'role_id' => $this->roleId($role),
                    'nursery_id' => $scopeShopId ? LegacyUuid::for('shops', $scopeShopId) : null,
                    'is_active' => (bool) ($row->is_active ?? true),
                    'email_verified_at' => $row->email_verified_at,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];
            },
        ]];
    }

    private function roleForLegacyUser(object $row): string
    {
        $permissions = $this->legacyPermissions()[$row->id] ?? [];

        if (in_array('super_admin', $permissions, true)) {
            return RoleName::SUPER_ADMIN;
        }

        if (in_array('staff', $permissions, true) && empty($row->shop_id)) {
            // Platform staff/designation employees operate the admin panel.
            return RoleName::ADMIN;
        }

        // Owners are recognized by the store_owner permission alone — their
        // shop link is shops.owner_id, NOT users.shop_id (staff-only column).
        if (in_array('store_owner', $permissions, true)) {
            return RoleName::NURSERY_OWNER;
        }

        if (in_array('staff', $permissions, true) && ! empty($row->shop_id)) {
            return RoleName::NURSERY_STAFF;
        }

        return RoleName::CUSTOMER;
    }

    /** @return array<int,int> owner user id -> first owned shop id */
    private function ownedShopIds(): array
    {
        if ($this->ownedShopIds === null) {
            $this->ownedShopIds = [];
            DB::table('shops')->orderBy('id')->select('id', 'owner_id')->each(function (object $shop) {
                $this->ownedShopIds[(int) $shop->owner_id] ??= (int) $shop->id;
            });
        }

        return $this->ownedShopIds;
    }

    /** @return array<string,int> */
    private function roleId(string $role): int
    {
        if ($this->roleIds === null) {
            $this->roleIds = DB::table('identity_roles')->pluck('id', 'name')->all();
        }

        return $this->roleIds[$role];
    }

    /** @return array<int, string[]> */
    private function legacyPermissions(): array
    {
        if ($this->permissionNames === null) {
            $this->permissionNames = [];
            DB::table('model_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
                ->where('model_has_permissions.model_type', 'like', '%User')
                ->select('model_has_permissions.model_id', 'permissions.name')
                ->orderBy('model_has_permissions.model_id')
                ->each(function (object $row) {
                    $this->permissionNames[(int) $row->model_id][] = $row->name;
                });
        }

        return $this->permissionNames;
    }
}
