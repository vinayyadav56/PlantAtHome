<?php

namespace Tests\Feature\Nursery;

use Tests\Feature\Identity\IdentityTestCase;

/**
 * Base for Nursery feature tests. Reuses the Identity setup (isolated sqlite,
 * outbox + identity tables, seeded roles + demo users for tokens) and adds the
 * nursery tables.
 */
abstract class NurseryTestCase extends IdentityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require base_path('app/Modules/Nursery/Database/Migrations/2026_07_29_000100_create_nursery_tables.php'))->up();
        (require base_path('app/Modules/Nursery/Database/Migrations/2026_07_29_000200_add_vendor_fields_to_nursery_tables.php'))->up();
        (require base_path('app/Modules/Nursery/Database/Migrations/2026_10_06_000100_add_delivery_mode_to_nursery_nurseries.php'))->up();
    }

    /**
     * A nursery registration as the admin sends it: since 2026-10-16 a create needs a complete
     * pickup address and phone (it becomes the vendor's pickup). The test's own fields win.
     */
    protected function registration(array $payload): array
    {
        return array_replace_recursive([
            'mobile'  => '9876543210',
            'address' => ['house_no' => '12', 'street_address' => 'Test Street', 'city' => 'Delhi', 'state' => 'Delhi', 'zip' => '110001'],
        ], $payload);
    }
}
