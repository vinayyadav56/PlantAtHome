<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Customer per-parcel tracking endpoint GET /api/orders/{tracking}/shipments.
 * Tracking numbers are enumerable (date + 6 digits), so the endpoint is gated:
 * guest orders need the per-order tracking_token (hash_equals), registered
 * orders need the owner (or super-admin); every failure returns the SAME
 * empty payload as an unknown tracking number (no existence leak). Parcels
 * carry a whitelisted item list and never vendor identity or platform cost.
 */
final class TrackingShipmentsTest extends TestCase
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

        $this->createSchema();
    }

    /** Minimal replicas — only the columns the endpoint's model graph touches. */
    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->string('shop_id')->nullable();
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('tracking_number')->unique();
            $t->string('tracking_token')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('order_status')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        // Allocation ledger: WHICH UNITS of an order line ride on WHICH shipment. Shipment::items()
        // is a belongsToMany through this table, so every stub that has `shipments` needs it too.
        Schema::create('shipment_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shipment_id');
            $t->unsignedBigInteger('order_item_id');
            $t->unsignedInteger('quantity')->default(1);
            $t->string('status', 32)->default('pending');
            $t->timestamps();
        });
        Schema::create('shipment_packages', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shipment_id');
            $t->unsignedSmallInteger('package_number')->default(1);
            $t->unsignedInteger('weight_g')->nullable();
            $t->decimal('length_cm', 6, 2)->nullable();
            $t->decimal('breadth_cm', 6, 2)->nullable();
            $t->decimal('height_cm', 6, 2)->nullable();
            $t->decimal('declared_value', 14, 2)->nullable();
            $t->string('contents', 255)->nullable();
            $t->boolean('fragile')->default(false);
            $t->timestamps();
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->string('fulfillment_mode')->nullable();
            $t->string('status')->nullable();
            $t->string('last_status')->nullable();
            $t->string('courier_name')->nullable();
            $t->string('awb_number')->nullable();
            $t->string('tracking_url')->nullable();
            $t->string('payment_method')->nullable();
            $t->decimal('shipping_cost', 10, 2)->nullable();
            $t->decimal('booked_cost', 10, 2)->nullable();
            $t->string('provider_order_id')->nullable();
            $t->string('provider_reference', 64)->nullable();
            $t->json('pickup_snapshot')->nullable();
            $t->unsignedBigInteger('pickup_location_id')->nullable();
            $t->integer('eta_days')->nullable();
            $t->date('expected_delivery_at')->nullable();
            $t->timestamp('shipped_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('shipment_id')->nullable();
            $t->unsignedBigInteger('assigned_shop_id')->nullable();
            $t->integer('order_quantity')->default(1);
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->text('image')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        // The Order model auto-eager-loads customer + products.variation_options ($with),
        // so the pivot + variation tables must exist even though these tests leave them empty.
        Schema::create('order_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id');
            $t->integer('order_quantity')->nullable();
            $t->decimal('unit_price', 10, 2)->nullable();
            $t->decimal('subtotal', 10, 2)->nullable();
            $t->unsignedBigInteger('variation_option_id')->nullable();
            $t->timestamps();
        });
        Schema::create('variation_options', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('title')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    private function makeOrder(array $attrs = []): int
    {
        return (int) DB::table('orders')->insertGetId(array_merge([
            'tracking_number' => '20260721' . random_int(100000, 999999),
            'tracking_token'  => null,
            'customer_id'     => null,
            'order_status'    => 'order-processing',
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $attrs));
    }

    private function makeShipment(int $orderId, array $attrs = []): int
    {
        return (int) DB::table('shipments')->insertGetId(array_merge([
            'order_id'         => $orderId,
            'shop_id'          => 42,
            'fulfillment_mode' => 'local',
            'status'           => 'assigned',
            'courier_name'     => 'Porter',
            'awb_number'       => 'AWB123',
            'tracking_url'     => 'https://porter.in/track/CRN1',
            'shipping_cost'    => 120.00,
            'booked_cost'      => 95.00,
            'provider_order_id' => 'CRN-SECRET',
            'eta_days'         => 1,
            'created_at'       => now(),
            'updated_at'       => now(),
        ], $attrs));
    }

    private function tracking(int $orderId): string
    {
        return (string) DB::table('orders')->where('id', $orderId)->value('tracking_number');
    }

    public function test_guest_order_with_valid_token_returns_parcels_with_items_only_safe_fields(): void
    {
        $orderId = $this->makeOrder(['tracking_token' => str_repeat('t', 48)]);
        $shipmentId = $this->makeShipment($orderId);
        $productId = DB::table('products')->insertGetId([
            'name' => 'Monstera Deliciosa', 'slug' => 'monstera-deliciosa',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId = DB::table('order_items')->insertGetId([
            'order_id' => $orderId, 'product_id' => $productId,
            'shipment_id' => $shipmentId, 'order_quantity' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // What the customer sees on the tracking page is the parcel's CONTENTS, which are the
        // allocation rows — `shipment_id` on the line is only the derived single-parcel pointer.
        DB::table('shipment_items')->insert([
            'shipment_id' => $shipmentId, 'order_item_id' => $itemId, 'quantity' => 2,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->getJson('/api/orders/' . $this->tracking($orderId) . '/shipments?token=' . str_repeat('t', 48));

        $res->assertOk();
        $shipments = $res->json('shipments');
        $this->assertCount(1, $shipments);
        $s = $shipments[0];
        $this->assertSame('Porter', $s['courier_name']);
        $this->assertSame('AWB123', $s['awb_number']);
        $this->assertSame([['name' => 'Monstera Deliciosa', 'slug' => 'monstera-deliciosa', 'image' => null, 'quantity' => 2]], $s['items']);
        // Vendor identity + platform cost must NEVER leak.
        foreach (['shop_id', 'shipping_cost', 'booked_cost', 'provider_order_id', 'booked_revenue'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $s);
        }
    }

    public function test_guest_order_with_wrong_or_missing_token_returns_empty(): void
    {
        $orderId = $this->makeOrder(['tracking_token' => str_repeat('t', 48)]);
        $this->makeShipment($orderId);
        $tracking = $this->tracking($orderId);

        $this->getJson('/api/orders/' . $tracking . '/shipments?token=WRONG')
            ->assertOk()->assertExactJson(['shipments' => []]);
        $this->getJson('/api/orders/' . $tracking . '/shipments')
            ->assertOk()->assertExactJson(['shipments' => []]);
    }

    public function test_legacy_tokenless_guest_order_stays_public(): void
    {
        $orderId = $this->makeOrder(['tracking_token' => null]);
        $this->makeShipment($orderId);

        $res = $this->getJson('/api/orders/' . $this->tracking($orderId) . '/shipments');

        $res->assertOk();
        $this->assertCount(1, $res->json('shipments'));
    }

    public function test_registered_order_requires_owner(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'o@x.test', 'password' => bcrypt('x')]);
        $stranger = User::create(['name' => 'S', 'email' => 's@x.test', 'password' => bcrypt('x')]);
        $orderId = $this->makeOrder(['customer_id' => $owner->id]);
        $this->makeShipment($orderId);
        $tracking = $this->tracking($orderId);

        // Unauthenticated → empty (same shape as unknown tracking; no existence leak).
        $this->getJson('/api/orders/' . $tracking . '/shipments')
            ->assertOk()->assertExactJson(['shipments' => []]);

        // A different registered user → empty (hasPermissionTo fails-closed without ACL tables).
        $this->actingAs($stranger)->getJson('/api/orders/' . $tracking . '/shipments')
            ->assertOk()->assertExactJson(['shipments' => []]);

        // The owner → parcels.
        $res = $this->actingAs($owner)->getJson('/api/orders/' . $tracking . '/shipments');
        $res->assertOk();
        $this->assertCount(1, $res->json('shipments'));
    }

    public function test_route_is_throttled(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => str_contains($r->uri(), 'orders/{tracking}/shipments')
        );
        $this->assertNotNull($route);
        $this->assertContains('throttle:60,1', $route->gatherMiddleware());
    }
}
