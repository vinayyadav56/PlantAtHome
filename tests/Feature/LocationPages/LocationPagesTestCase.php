<?php

namespace Tests\Feature\LocationPages;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Location-pages harness (Legal pattern): sqlite :memory: running the REAL
 * migration file, plus the minimal cities / product_city_availability / users
 * tables the seeder command and controller lean on.
 */
abstract class LocationPagesTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        $migration = require base_path('packages/marvel/database/migrations/2026_08_31_000400_create_location_pages_table.php');
        $migration->up();

        Schema::create('cities', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('state_name')->nullable();
            $t->boolean('is_serviceable')->default(false);
            $t->boolean('is_subdivision')->default(false);
            $t->string('status')->default('active');
            $t->timestamps();
        });

        // Rollup rows only (variation_option_id = 0), mirroring the columns
        // availabilityProductIdQuery actually touches.
        Schema::create('product_city_availability', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('city');
            $t->unsignedBigInteger('variation_option_id')->default(0);
            $t->boolean('has_local')->default(false);
            $t->boolean('has_courier')->default(false);
            $t->integer('stock')->nullable();
            $t->integer('stock_override')->nullable();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
        });

        // Constructing AvailabilityService builds a PricingService, which
        // reads settings — the table must exist (same trick as LegalTestCase).
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->json('options');
            $t->string('language')->default('en');
            $t->timestamps();
        });
        DB::table('settings')->insert([
            'options' => json_encode(['siteTitle' => 'PlantAtHome']),
            'language' => 'en',
        ]);
    }

    protected function city(string $name, array $overrides = []): int
    {
        return (int) DB::table('cities')->insertGetId(array_merge([
            'name' => $name,
            'state_name' => 'Testland',
            'is_serviceable' => true,
            'is_subdivision' => false,
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    /** Give a city live supply so the honest-supply gate passes. */
    protected function supply(string $cityKey, int $productId = 1): void
    {
        DB::table('product_city_availability')->insert([
            'product_id' => $productId,
            'city' => $cityKey,
            'variation_option_id' => 0,
            'has_local' => true,
            'has_courier' => false,
            'stock' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
