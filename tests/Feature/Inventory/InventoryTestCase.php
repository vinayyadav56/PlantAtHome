<?php

namespace Tests\Feature\Inventory;

use Tests\Feature\Identity\IdentityTestCase;

/**
 * Base for Inventory tests. Reuses the Identity setup (auth + outbox) and adds
 * the inv_* tables. Inventory stores opaque sellable_uuids, so tests use
 * synthetic SKU uuids without needing real catalog rows.
 */
abstract class InventoryTestCase extends IdentityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $dir = base_path('app/Modules/Inventory/Database/Migrations');
        foreach (['2026_07_21_000000_create_inventory_tables.php'] as $file) {
            (require $dir.'/'.$file)->up();
        }
    }
}
