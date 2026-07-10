<?php

namespace Tests\Feature\Sales;

use Tests\Feature\Pricing\PricingTestCase;

/**
 * Base for Sales tests. Builds on Pricing (identity + catalog + config + rules +
 * pricing) and adds the inv_* and sales_* tables, so the full cart → checkout →
 * reserve → pay → split order flow runs end-to-end.
 */
abstract class SalesTestCase extends PricingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require base_path('app/Modules/Inventory/Database/Migrations/2026_07_21_000000_create_inventory_tables.php'))->up();
        (require base_path('app/Modules/Sales/Database/Migrations/2026_07_24_000000_create_sales_cart_checkout_tables.php'))->up();
        (require base_path('app/Modules/Sales/Database/Migrations/2026_07_24_000001_create_sales_order_tables.php'))->up();
    }
}
