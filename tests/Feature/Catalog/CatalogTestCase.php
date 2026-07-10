<?php

namespace Tests\Feature\Catalog;

use Tests\Feature\Identity\IdentityTestCase;

/**
 * Base for Catalog feature tests. Reuses the Identity setup (isolated sqlite,
 * outbox + processed_events, identity tables, seeded roles + demo users so we
 * can obtain admin/nursery/customer tokens) and adds the catalog tables.
 */
abstract class CatalogTestCase extends IdentityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $dir = base_path('app/Modules/Catalog/Database/Migrations');
        foreach ([
            '2026_07_18_000000_create_catalog_categories_table.php',
            '2026_07_18_000001_create_catalog_products_tables.php',
            '2026_07_18_000002_create_catalog_attributes_tables.php',
            '2026_07_18_000003_create_catalog_product_variants_table.php',
            '2026_07_18_000004_create_catalog_product_proposals_table.php',
        ] as $file) {
            (require $dir.'/'.$file)->up();
        }
    }
}
