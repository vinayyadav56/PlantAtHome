<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Shop;
use Marvel\Services\Courier\ShippingServiceClient;
use Tests\TestCase;

/**
 * Pickup registration: the "Register pickup" flow must actually register the shop-{id} nickname
 * AT Shiprocket (via the shipping service) with coordinates — not just stamp a local column.
 * An unregistered nickname 422s every booking; coordinates are the Quick prerequisite.
 */
final class RegisterPickupTest extends TestCase
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
            'services.shipping_service.url'     => 'https://ship.test',
            'services.shipping_service.api_key' => 'k',
        ]);
        DB::purge('sqlite');
        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->text('address')->nullable();
            $t->text('settings')->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->string('pickup_location_name')->nullable();
            $t->string('pickup_postcode')->nullable();
            $t->timestamps();
        });
    }

    public function test_register_pickup_sends_nickname_address_and_coordinates(): void
    {
        Http::fake([
            'ship.test/v1/partners/shiprocket/register-pickup' => Http::response([
                'code' => 'shiprocket', 'ok' => true, 'outcome' => 'registered',
            ], 200),
        ]);

        $shop = Shop::create([
            'name'     => 'Delhi Nursery',
            'address'  => ['street_address' => '1 Garden Lane', 'city' => 'Delhi', 'state' => 'Delhi', 'zip' => '110001', 'phone' => '+91 98765 43210'],
            'settings' => ['location' => ['lat' => 28.6139, 'lng' => 77.209]],
        ]);

        $res = (new ShippingServiceClient())->registerPickup($shop);

        $this->assertTrue($res['ok']);
        $this->assertSame('registered', $res['outcome']);
        Http::assertSent(function ($request) use ($shop) {
            $b = $request->data();
            return $b['name'] === 'shop-' . $shop->id
                && $b['pincode'] === '110001'
                && $b['phone'] === '919876543210'
                && abs($b['lat'] - 28.6139) < 0.0001
                && abs($b['lng'] - 77.209) < 0.0001;
        });
    }

    public function test_register_pickup_surfaces_the_partner_error(): void
    {
        Http::fake([
            'ship.test/*' => Http::response([
                'code' => 'shiprocket', 'ok' => false,
                'error' => 'shiprocket: Please provide a valid phone number (422)',
            ], 200),
        ]);

        $shop = Shop::create(['name' => 'V', 'address' => ['city' => 'Delhi', 'zip' => '110001']]);
        $res = (new ShippingServiceClient())->registerPickup($shop);

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('valid phone', $res['error']);
    }
}
