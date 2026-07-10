<?php

namespace App\Modules\Identity\Domain;

/**
 * The default role → permission grants, as data. The seeder materializes this
 * into identity_permission_role rows; authorization reads the persisted grants,
 * so an operator can override the defaults per environment without a deploy.
 * super_admin is granted every permission implicitly (see grantsFor).
 */
final class AccessMatrix
{
    /** @return array<string,string[]> role name => permission names */
    public static function map(): array
    {
        return [
            RoleName::CUSTOMER => [
                Permission::SHOP_BROWSE,
                Permission::ORDERS_OWN,
            ],
            RoleName::NURSERY_STAFF => [
                Permission::NURSERY_VIEW_OWN,
            ],
            RoleName::NURSERY_OWNER => [
                Permission::NURSERY_VIEW_OWN,
                Permission::NURSERY_MANAGE_OWN,
                Permission::CATALOG_PROPOSE,
            ],
            RoleName::ADMIN => [
                Permission::ADMIN_ACCESS,
                Permission::CATALOG_MANAGE,
                Permission::CONFIG_MANAGE,
                Permission::RULES_MANAGE,
                Permission::INVENTORY_MANAGE,
                Permission::PRICING_MANAGE,
                Permission::ORDERS_VIEW_ALL,
            ],
            RoleName::SUPER_ADMIN => Permission::all(), // everything
        ];
    }

    /** @return string[] the permissions a role grants by default */
    public static function grantsFor(string $role): array
    {
        if ($role === RoleName::SUPER_ADMIN) {
            return Permission::all();
        }

        return self::map()[$role] ?? [];
    }
}
