<?php

namespace Tests\Feature\Pricing;

use Tests\Feature\Rules\RulesTestCase;

/**
 * Base for Pricing tests. Builds on the Rules setup (identity + catalog + config
 * + rule tables) and adds the pricing_* tables, so pricing can exercise the
 * Rules Engine (PRICING scope) for discounts.
 */
abstract class PricingTestCase extends RulesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $dir = base_path('app/Modules/Pricing/Database/Migrations');
        foreach (['2026_07_22_000000_create_pricing_tables.php'] as $file) {
            (require $dir.'/'.$file)->up();
        }
    }
}
