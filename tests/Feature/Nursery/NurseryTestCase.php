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
    }
}
