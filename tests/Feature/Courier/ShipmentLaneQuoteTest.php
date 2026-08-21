<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Shipment;
use Marvel\Services\Courier\CourierService;
use Tests\TestCase;

/**
 * Quoting a lane other than the shipment's own.
 *
 * Partners are mode-exclusive — Shiprocket serves `courier`, Porter and Borzo serve
 * `instant`/`same_city` — so a single-lane quote can only ever show half the delivery options.
 * The admin asks for each lane in turn to list them all, which means quoting must be able to
 * take a lane AND must never persist it: looking at a price is not choosing a lane.
 */
final class ShipmentLaneQuoteTest extends TestCase
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
            $t->string('provider_reference', 64)->nullable();
            $t->json('pickup_snapshot')->nullable();
            $t->unsignedBigInteger('pickup_location_id')->nullable();
            $t->string('awb_number')->nullable();
            $t->timestamps();
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
        Schema::create('products', function (Blueprint $t) {
            // Master Catalog membership + listing switch. Defaulted TRUE in stubs, not FALSE:
            // production starts empty by design, but a fixture that had to opt every product in
            // would make each existing test assert the new gate instead of what it was written for.
            $t->boolean('is_available_product')->default(true);
            $t->boolean('listing_enabled')->default(true);
            $t->timestamp('available_at')->nullable();
            $t->unsignedBigInteger('available_by')->nullable();
            $t->boolean('track_stock')->default(false);
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->unsignedInteger('weight')->default(0);
            $t->decimal('length', 6, 2)->default(0);
            $t->decimal('breadth', 6, 2)->default(0);
            $t->decimal('height', 6, 2)->default(0);
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
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
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->timestamps();
        });

        DB::table('shops')->insert([
            'id' => 5, 'name' => 'Vendor 5',
            'address' => json_encode(['street_address' => '1 Lane', 'city' => 'Delhi', 'zip' => '110001']),
        ]);
        // Courier master switch. Without it quoteShipment returns early and never calls the
        // service, which would make every assertion below pass against zero requests.
        DB::table('settings')->insert([
            'id' => 1, 'language' => 'en',
            'options' => json_encode(['courier' => ['enabled' => true]]),
        ]);
    }

    private function makeShipment(array $attrs = []): Shipment
    {
        $order = Order::create([
            'tracking_number' => 'T' . random_int(10000000, 99999999),
            'shipping_address' => json_encode(['city' => 'Delhi', 'zip' => '110001']),
        ]);
        return Shipment::create(array_merge([
            'order_id' => $order->id, 'shop_id' => 5,
            'fulfillment_mode' => 'local', 'mode' => 'same_city', 'status' => 'pending',
        ], $attrs));
    }

    /** @return string the `mode` the shipping service was actually asked to quote */
    private function quotedMode(Shipment $shipment, ?string $askFor): string
    {
        Http::fake(['*/v1/quotes' => Http::response(['ok' => true, 'quotes' => []], 200)]);
        (new CourierService())->quoteShipment($shipment->fresh(), false, $askFor);

        foreach (Http::recorded() as [$request, $response]) {
            return (string) ($request->data()['mode'] ?? '');
        }
        $this->fail('the shipping service was never called — the assertion would have been vacuous');
    }

    public function test_quote_uses_the_shipments_own_lane_when_none_is_asked_for(): void
    {
        $this->assertSame('same_city', $this->quotedMode($this->makeShipment(), null));
    }

    public function test_quote_can_be_asked_for_a_different_lane(): void
    {
        // This is what lets the admin list courier options on a hyperlocal shipment.
        $this->assertSame('courier', $this->quotedMode($this->makeShipment(), 'courier'));
    }

    public function test_quoting_another_lane_does_not_persist_it(): void
    {
        $shipment = $this->makeShipment(['mode' => 'same_city']);

        $this->quotedMode($shipment, 'courier');

        $this->assertSame(
            'same_city',
            $shipment->fresh()->mode,
            'looking at another lane must not choose it — POST shipping-mode is the write path',
        );
    }
}
