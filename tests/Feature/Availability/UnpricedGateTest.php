<?php

declare(strict_types=1);

namespace Tests\Feature\Availability;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * hide_unpriced must not hide a ₹0-master product that vendors HAVE priced for
 * the shopper's city (the projection prices AFTER pagination, so the old
 * master-price-only clause filtered such products out before the city overlay
 * could ever price them). The EXISTS is deliberately city-scoped: with no city
 * on the request, unpriced products stay hidden (no ₹0.00 cards on city-less
 * SSR), and an alias city (Gurgaon) must resolve to its canonical key.
 */
final class UnpricedGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        // AvailabilityService's constructor chain reads Settings.
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->decimal('price')->nullable();
            $t->decimal('sale_price')->nullable();
            $t->decimal('max_price')->nullable();
            $t->timestamps();
        });
        Schema::create('product_city_availability', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('city');
            $t->unsignedBigInteger('variation_option_id')->default(0);
            $t->decimal('display_price')->nullable();
            $t->timestamps();
        });

        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Master-priced', 'price' => 100, 'sale_price' => null, 'max_price' => null],
            ['id' => 2, 'name' => 'City-priced only', 'price' => null, 'sale_price' => null, 'max_price' => null],
            ['id' => 3, 'name' => 'Truly unpriced', 'price' => null, 'sale_price' => null, 'max_price' => null],
        ]);
        DB::table('product_city_availability')->insert([
            ['product_id' => 2, 'city' => 'gurugram', 'variation_option_id' => 0, 'display_price' => 479],
        ]);
    }

    private function gatedIds(array $query): array
    {
        $controller = app(\Marvel\Http\Controllers\ProductController::class);
        $ref = new \ReflectionMethod($controller, 'applyUnpricedGate');
        $ref->setAccessible(true);
        $request = Request::create('/products', 'GET', $query);
        $q = $ref->invoke($controller, DB::table('products'), $request);
        return $q->orderBy('products.id')->pluck('products.id')->map(fn ($i) => (int) $i)->all();
    }

    public function test_no_city_keeps_master_price_only_semantics(): void
    {
        $this->assertSame([1], $this->gatedIds(['hide_unpriced' => 1]));
    }

    public function test_city_scoped_exists_admits_city_priced_products_incl_alias(): void
    {
        // Alias: shopper says Gurgaon, projection rows use the canonical key.
        $this->assertSame([1, 2], $this->gatedIds(['hide_unpriced' => 1, 'city' => 'Gurgaon']));
        // Another city with no rows for product 2 → hidden there.
        $this->assertSame([1], $this->gatedIds(['hide_unpriced' => 1, 'city' => 'Delhi']));
    }

    public function test_gate_off_returns_everything(): void
    {
        $this->assertSame([1, 2, 3], $this->gatedIds([]));
    }
}
