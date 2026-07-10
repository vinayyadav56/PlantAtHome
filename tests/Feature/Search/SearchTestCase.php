<?php

namespace Tests\Feature\Search;

use Tests\Feature\Pricing\PricingTestCase;

/**
 * Base for Search tests. Builds on Pricing (identity + catalog + config + rules +
 * pricing) and adds inventory, serviceability and the search projection, so
 * event-driven indexing + city-aware filtering run end-to-end.
 */
abstract class SearchTestCase extends PricingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require base_path('app/Modules/Inventory/Database/Migrations/2026_07_21_000000_create_inventory_tables.php'))->up();
        (require base_path('app/Modules/Serviceability/Database/Migrations/2026_07_23_000000_create_serviceability_tables.php'))->up();
        (require base_path('app/Modules/Search/Database/Migrations/2026_07_25_000000_create_search_products_table.php'))->up();
    }
}
