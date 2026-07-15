<?php

namespace Tests\Feature\Serviceability;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Catalog\CatalogTestCase;

/**
 * Base for Serviceability tests. Builds on Catalog (identity + catalog products)
 * and adds the inv_* and svc_* tables, so city-aware product availability
 * (coverage × stock) can be exercised end-to-end. Also creates minimal legacy
 * replicas (states/cities/shops/vendor_service_areas — only the columns the
 * coverage code reads) plus the Delivery Coverage migrations, which exercise
 * the real alter paths against those legacy tables.
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

        $this->createLegacyGeoTables();

        $dir = base_path('app/Modules/Serviceability/Database/Migrations');
        foreach ([
            '2026_07_15_100001_create_countries_table.php',
            '2026_07_15_100002_add_country_id_to_states.php',
            '2026_07_15_100003_create_districts_table.php',
            '2026_07_15_100004_add_district_id_to_cities.php',
            '2026_07_15_100005_create_postal_codes_table.php',
            '2026_07_15_100006_create_vendor_coverage_rules_table.php',
            '2026_07_15_100007_create_vendor_covered_pincodes_table.php',
            '2026_07_15_100008_create_coverage_audit_logs_table.php',
            '2026_07_15_100009_add_source_to_vendor_service_areas.php',
            '2026_07_15_100010_add_delivery_coverage_to_orders.php',
            '2026_07_15_100011_create_delivery_notify_requests_table.php',
        ] as $file) {
            (require $dir.'/'.$file)->up();
        }
    }

    /** Minimal legacy tables (NurseryBackfillTest pattern) — only columns coverage reads. */
    private function createLegacyGeoTables(): void
    {
        Schema::create('states', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->unique();
            $t->string('code', 8)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('cities', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->unsignedBigInteger('state_id')->nullable();
            $t->string('state_name')->nullable();
            $t->string('status', 16)->default('active');
            $t->boolean('is_serviceable')->default(true);
            $t->timestamps();
        });

        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->boolean('is_active')->default(false);
            $t->timestamps();
        });

        Schema::create('vendor_service_areas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id')->index();
            $t->string('city')->index();
            $t->string('pincode', 12)->nullable();
            $t->string('fulfillment_mode')->default('local');
            $t->unsignedSmallInteger('eta_days')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['shop_id', 'city', 'pincode']);
        });
    }
}
