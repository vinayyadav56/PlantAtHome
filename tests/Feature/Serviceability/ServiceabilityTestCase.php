<?php

namespace Tests\Feature\Serviceability;

use Tests\Feature\Catalog\CatalogTestCase;

/**
 * Base for Serviceability tests. Builds on Catalog (identity + catalog products)
 * and adds the inv_* and svc_* tables, so city-aware product availability
 * (coverage × stock) can be exercised end-to-end.
 */
abstract class ServiceabilityTestCase extends CatalogTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require base_path('app/Modules/Inventory/Database/Migrations/2026_07_21_000000_create_inventory_tables.php'))->up();
        (require base_path('app/Modules/Serviceability/Database/Migrations/2026_07_23_000000_create_serviceability_tables.php'))->up();
        // Pricing tables: CoverageService::cheapestFulfillingNursery ranks vendors by price.
        (require base_path('app/Modules/Pricing/Database/Migrations/2026_07_22_000000_create_pricing_tables.php'))->up();
    }
}
