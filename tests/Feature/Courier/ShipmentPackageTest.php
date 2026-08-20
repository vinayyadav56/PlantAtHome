<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderItem;
use Marvel\Database\Models\Shipment;
use Marvel\Services\Courier\ShippingServiceClient;
use Tests\TestCase;

/**
 * Parcel weight + dimensions reaching the partner.
 *
 * Couriers bill on volumetric weight, so these are a money path: the operator's
 * correction must win over the estimate, and the estimate must fall back
 * through product data → the admin's configured default → the shipped default.
 * Dimensions previously never left the monolith at all.
 */
final class ShipmentPackageTest extends TestCase
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
            'services.shipping_service.url' => 'https://ship.test',
            'services.shipping_service.api_key' => 'k',
            'services.shipping_service.enabled' => true,
        ]);
        DB::purge('sqlite');

        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('tracking_number')->unique();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('order_status')->nullable();
            $t->string('payment_status')->nullable();
            $t->string('payment_gateway')->nullable();
            $t->text('shipping_address')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('shipment_id')->nullable();
            $t->integer('order_quantity')->default(1);
            $t->decimal('unit_price')->default(0);
            $t->timestamps();
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->string('fulfillment_mode')->nullable();
            $t->string('delivery_mode')->nullable();
            $t->string('mode')->nullable();
            $t->string('status')->default('pending');
            $t->decimal('cod_amount')->nullable();
            $t->unsignedInteger('weight_g')->nullable();
            $t->decimal('length_cm', 6, 2)->nullable();
            $t->decimal('breadth_cm', 6, 2)->nullable();
            $t->decimal('height_cm', 6, 2)->nullable();
            $t->string('provider')->nullable();
            $t->string('provider_order_id')->nullable();
            $t->string('provider_shipment_id')->nullable();
            $t->string('awb_number')->nullable();
            $t->string('courier_name')->nullable();
            $t->string('tracking_url')->nullable();
            $t->string('payment_method')->nullable();
            $t->decimal('booked_cost')->nullable();
            $t->string('last_status')->nullable();
            $t->timestamp('last_status_at')->nullable();
            $t->string('failure_reason')->nullable();
            $t->string('cancelled_reason')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->unsignedTinyInteger('simulation_flow_type')->nullable();
            $t->timestamp('simulation_started_at')->nullable();
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('sku')->nullable();
            $t->unsignedInteger('weight')->default(0);
            $t->decimal('length', 6, 2)->default(0);
            $t->decimal('breadth', 6, 2)->default(0);
            $t->decimal('height', 6, 2)->default(0);
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->text('address')->nullable();
            $t->text('settings')->nullable();
            $t->string('pickup_location_name')->nullable();
            $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->timestamps();
        });
        // The Order model eager-loads products WITH pivot columns — a bare
        // join table makes every test explode in the relation, not the code.
        Schema::create('order_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('variation_option_id')->nullable();
            $t->integer('order_quantity')->nullable();
            $t->string('unit_price')->nullable();
            $t->string('subtotal')->nullable();
            $t->timestamps();
        });
        Schema::create('variation_options', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('title')->nullable();
            $t->timestamps();
        });
        Schema::create('reviews', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->timestamps();
        });

        DB::table('shops')->insert([
            'id' => 5, 'name' => 'Vendor 5',
            'address' => json_encode(['street_address' => '1 Lane', 'city' => 'Delhi', 'zip' => '110001']),
        ]);
    }

    /** @return array the package fields of the body sent to /v1/shipments */
    private function bookAndCapture(Shipment $shipment): array
    {
        Http::fake(['*/v1/shipments' => Http::response(['partner' => 'shiprocket', 'awb_number' => 'A1'], 200)]);
        (new ShippingServiceClient())->book($shipment->fresh(['items.product', 'order', 'shop']), 'courier', false, 0);

        $body = [];
        foreach (Http::recorded() as [$request, $response]) {
            $body = $request->data();
        }
        return $body;
    }

    private int $nextProductId = 1;

    private function makeShipment(array $productAttrs = [], array $shipmentAttrs = []): Shipment
    {
        $order = Order::create([
            'tracking_number' => 'T' . random_int(10000000, 99999999),
            'shipping_address' => json_encode(['city' => 'Delhi', 'zip' => '110001']),
        ]);
        // Fresh product per call — a test that builds two shipments must not
        // collide on the primary key.
        $productId = $this->nextProductId++;
        DB::table('products')->insert(array_merge([
            'id' => $productId, 'name' => 'Areca Palm', 'sku' => 'AP' . $productId, 'weight' => 0,
            'length' => 0, 'breadth' => 0, 'height' => 0,
        ], $productAttrs));
        $shipment = Shipment::create(array_merge([
            'order_id' => $order->id, 'shop_id' => 5, 'fulfillment_mode' => 'courier', 'status' => 'pending',
        ], $shipmentAttrs));
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $productId, 'shipment_id' => $shipment->id, 'order_quantity' => 2,
        ]);
        return $shipment;
    }

    public function test_dimensions_default_to_the_standard_parcel(): void
    {
        $body = $this->bookAndCapture($this->makeShipment());

        $this->assertSame(20.0, $body['length_cm']);
        $this->assertSame(15.0, $body['breadth_cm']);
        $this->assertSame(15.0, $body['height_cm']);
    }

    public function test_product_dimensions_beat_the_default(): void
    {
        $body = $this->bookAndCapture($this->makeShipment([
            'length' => 40, 'breadth' => 30, 'height' => 55,
        ]));

        $this->assertSame(40.0, $body['length_cm']);
        $this->assertSame(30.0, $body['breadth_cm']);
        $this->assertSame(55.0, $body['height_cm']);
    }

    public function test_operator_override_beats_everything(): void
    {
        $body = $this->bookAndCapture($this->makeShipment(
            ['weight' => 900, 'length' => 40, 'breadth' => 30, 'height' => 55],
            ['weight_g' => 4500, 'length_cm' => 60, 'breadth_cm' => 45, 'height_cm' => 70],
        ));

        $this->assertSame(4500, $body['weight_g'], 'the operator can see the parcel; the estimate cannot');
        $this->assertSame(60.0, $body['length_cm']);
        $this->assertSame(45.0, $body['breadth_cm']);
        $this->assertSame(70.0, $body['height_cm']);
    }

    public function test_weight_falls_back_to_product_sum_then_500g_per_unit(): void
    {
        // product weight 800g × qty 2
        $this->assertSame(1600, $this->bookAndCapture($this->makeShipment(['weight' => 800]))['weight_g']);

        Http::fake();
        // no product weight → 500g × qty 2
        $this->assertSame(1000, $this->bookAndCapture($this->makeShipment())['weight_g']);
    }

    public function test_partial_dimension_data_falls_through_instead_of_sending_zeros(): void
    {
        // Length only: an incomplete triple must NOT reach the partner as 40×0×0.
        $body = $this->bookAndCapture($this->makeShipment(['length' => 40]));

        $this->assertSame(20.0, $body['length_cm']);
        $this->assertSame(15.0, $body['breadth_cm']);
        $this->assertSame(15.0, $body['height_cm']);
    }

    public function test_configured_default_package_is_used_when_products_have_no_dims(): void
    {
        DB::table('settings')->insert([
            'language' => 'en',
            'options' => json_encode(['courier' => ['default_package' => [
                'weight' => 500, 'length' => 35, 'breadth' => 25, 'height' => 18,
            ]]]),
        ]);

        $body = $this->bookAndCapture($this->makeShipment());

        $this->assertSame(35.0, $body['length_cm'], 'the admin-configured parcel was dead config until now');
        $this->assertSame(25.0, $body['breadth_cm']);
        $this->assertSame(18.0, $body['height_cm']);
    }
}
