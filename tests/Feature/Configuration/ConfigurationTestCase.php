<?php

namespace Tests\Feature\Configuration;

use Illuminate\Support\Facades\Cache;
use Tests\Feature\Catalog\CatalogTestCase;

/**
 * Base for Configuration Engine tests. Builds on the Catalog setup (identity +
 * catalog tables, seeded users) and adds the config_* tables. Uses an isolated
 * array cache flushed per test so the resolution cache never crosses tests.
 */
abstract class ConfigurationTestCase extends CatalogTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();

        $dir = base_path('app/Modules/Configuration/Database/Migrations');
        foreach ([
            '2026_07_19_000000_create_config_groups_options_tables.php',
            '2026_07_19_000001_create_config_assignments_compat_enablement_tables.php',
        ] as $file) {
            (require $dir.'/'.$file)->up();
        }
    }
}
