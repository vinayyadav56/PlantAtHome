<?php

namespace Tests\Feature\Marketing;

use App\Modules\Notifications\Database\Seeders\NotificationTemplateSeeder;
use Tests\Feature\Sales\SalesTestCase;

/**
 * Base for Phase 10 (Promotions / Notifications / CMS / Analytics). Builds on the
 * full Sales stack and adds the four supporting-domain tables + default
 * notification templates.
 */
abstract class MarketingTestCase extends SalesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'app/Modules/Promotions/Database/Migrations/2026_07_26_000000_create_promotions_tables.php',
            'app/Modules/Notifications/Database/Migrations/2026_07_26_000001_create_notifications_tables.php',
            'app/Modules/Cms/Database/Migrations/2026_07_26_000002_create_cms_tables.php',
            'app/Modules/Analytics/Database/Migrations/2026_07_26_000003_create_analytics_tables.php',
        ] as $migration) {
            (require base_path($migration))->up();
        }

        (new NotificationTemplateSeeder())->run();
    }
}
