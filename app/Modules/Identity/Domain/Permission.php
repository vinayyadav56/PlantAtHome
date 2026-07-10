<?php

namespace App\Modules\Identity\Domain;

/**
 * Fine-grained capabilities. Middleware and policies check these (never a role
 * name literal), so re-assigning a capability to a different role is a data
 * change in the AccessMatrix, not a code change. New modules add their own
 * permission constants here as they land.
 */
final class Permission
{
    // Platform administration
    public const ADMIN_ACCESS      = 'admin.access';       // reach the admin surface at all
    public const CATALOG_MANAGE     = 'catalog.manage';    // create/edit master catalog (Phase 2)
    public const CONFIG_MANAGE      = 'config.manage';     // configuration groups/options (Phase 3)
    public const RULES_MANAGE       = 'rules.manage';      // author data-driven rules (Phase 4)
    public const INVENTORY_MANAGE   = 'inventory.manage';  // stock + reservations (Phase 5)
    public const SERVICEABILITY_MANAGE = 'serviceability.manage'; // cities + coverage (Phase 7)
    public const PROMOTIONS_MANAGE  = 'promotions.manage';  // coupons + campaigns (Phase 10)
    public const CMS_MANAGE         = 'cms.manage';         // pages + banners (Phase 10)
    public const ANALYTICS_VIEW     = 'analytics.view';     // KPI dashboards (Phase 10)
    public const PRICING_MANAGE     = 'pricing.manage';    // pricing policies (Phase 6)
    public const ORDERS_VIEW_ALL    = 'orders.view_all';   // see every vendor's orders
    public const USERS_MANAGE       = 'users.manage';      // manage identity users

    // Nursery (vendor) self-service — always scoped to the caller's own nursery
    public const NURSERY_MANAGE_OWN = 'nursery.manage_own'; // own stock/pricing/sub-orders
    public const NURSERY_VIEW_OWN   = 'nursery.view_own';   // read own dashboard
    public const CATALOG_PROPOSE    = 'catalog.propose';    // file a product proposal (Phase 2)

    // Customer
    public const SHOP_BROWSE        = 'shop.browse';
    public const ORDERS_OWN         = 'orders.own';         // own orders only

    /** @return string[] every defined permission name */
    public static function all(): array
    {
        return [
            self::ADMIN_ACCESS,
            self::CATALOG_MANAGE,
            self::CONFIG_MANAGE,
            self::RULES_MANAGE,
            self::INVENTORY_MANAGE,
            self::SERVICEABILITY_MANAGE,
            self::PROMOTIONS_MANAGE,
            self::CMS_MANAGE,
            self::ANALYTICS_VIEW,
            self::PRICING_MANAGE,
            self::ORDERS_VIEW_ALL,
            self::USERS_MANAGE,
            self::NURSERY_MANAGE_OWN,
            self::NURSERY_VIEW_OWN,
            self::CATALOG_PROPOSE,
            self::SHOP_BROWSE,
            self::ORDERS_OWN,
        ];
    }

    public static function label(string $name): string
    {
        return ucfirst(str_replace(['.', '_'], [' — ', ' '], $name));
    }
}
