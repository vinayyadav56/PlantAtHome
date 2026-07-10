<?php

namespace Tests\Feature\Rules;

use Tests\Feature\Configuration\ConfigurationTestCase;

/**
 * Base for Rules Engine tests. Builds on the Configuration setup (identity +
 * catalog + config tables, array cache) and adds the rule_* tables so the engine
 * and its wiring into configuration resolution can be exercised end-to-end.
 */
abstract class RulesTestCase extends ConfigurationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $dir = base_path('app/Modules/Rules/Database/Migrations');
        foreach (['2026_07_20_000000_create_rule_tables.php'] as $file) {
            (require $dir.'/'.$file)->up();
        }
    }
}
