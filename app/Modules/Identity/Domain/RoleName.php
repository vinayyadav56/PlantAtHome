<?php

namespace App\Modules\Identity\Domain;

/**
 * The five canonical roles (Section 13). Roles form a simple hierarchy by
 * `level` — higher level ⇒ broader authority — but authorization decisions are
 * made on explicit permissions (see AccessMatrix), not on level comparisons.
 * Level exists only for display/ordering and coarse "is at least" checks.
 */
final class RoleName
{
    public const CUSTOMER      = 'customer';
    public const NURSERY_STAFF = 'nursery_staff';
    public const NURSERY_OWNER = 'nursery_owner';
    public const ADMIN         = 'admin';
    public const SUPER_ADMIN   = 'super_admin';

    /** Platform-side roles that bypass nursery scoping (see EnsureNurseryScope). */
    public const PLATFORM_ADMINS = [self::ADMIN, self::SUPER_ADMIN];

    /** Roles bound to a single nursery. */
    public const NURSERY_ROLES = [self::NURSERY_OWNER, self::NURSERY_STAFF];

    /** name => hierarchy level */
    public const LEVELS = [
        self::CUSTOMER      => 10,
        self::NURSERY_STAFF => 20,
        self::NURSERY_OWNER => 30,
        self::ADMIN         => 40,
        self::SUPER_ADMIN   => 50,
    ];

    /** name => human label */
    public const LABELS = [
        self::CUSTOMER      => 'Customer',
        self::NURSERY_STAFF => 'Nursery Staff',
        self::NURSERY_OWNER => 'Nursery Owner',
        self::ADMIN         => 'Admin',
        self::SUPER_ADMIN   => 'Super Admin',
    ];

    /** @return string[] all role names */
    public static function all(): array
    {
        return array_keys(self::LEVELS);
    }

    public static function isPlatformAdmin(string $role): bool
    {
        return in_array($role, self::PLATFORM_ADMINS, true);
    }

    public static function isNurseryRole(string $role): bool
    {
        return in_array($role, self::NURSERY_ROLES, true);
    }

    public static function exists(string $role): bool
    {
        return array_key_exists($role, self::LEVELS);
    }
}
