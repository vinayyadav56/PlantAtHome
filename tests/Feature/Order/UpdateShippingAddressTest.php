<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Http\Controllers\OrderAssignmentController;
use Tests\TestCase;

/**
 * OrderAssignmentController::updateShippingAddress — the confirm-dispatch popup's "save to order"
 * step. Merges over the existing address: a text-only edit must NOT blank the GPS pin, and a pin
 * nudge must NOT blank the typed street.
 */
final class UpdateShippingAddressTest extends TestCase
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

        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('tracking_number')->nullable();
            $t->json('shipping_address')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        // Order model default eager loads.
        foreach (['users' => ['name'], 'products' => ['name']] as $tbl => $cols) {
            Schema::create($tbl, function (Blueprint $t) use ($cols) {
                $t->bigIncrements('id');
                foreach ($cols as $c) {
                    $t->string($c)->nullable();
                }
                $t->timestamps();
                $t->timestamp('deleted_at')->nullable();
            });
        }
        Schema::create('order_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id');
            $t->integer('order_quantity')->nullable();
            $t->string('unit_price')->nullable();
            $t->string('subtotal')->nullable();
            $t->unsignedBigInteger('variation_option_id')->nullable();
            $t->timestamps();
        });
    }

    private function makeOrder(): Order
    {
        return Order::create([
            'tracking_number'  => 'T1',
            'shipping_address' => [
                'street_address' => 'House 109, Sector 47',
                'city'           => 'New Delhi',
                'state'          => 'Delhi',
                'zip'            => '110004',
                'location'       => ['lat' => 28.6, 'lng' => 77.2],
            ],
        ]);
    }

    private function saveAddr(Order $order, array $body): array
    {
        return (new OrderAssignmentController())->updateShippingAddress($order->id, Request::create('/', 'PUT', $body));
    }

    public function test_text_edit_preserves_the_gps_pin(): void
    {
        $order = $this->makeOrder();

        $this->saveAddr($order, ['street_address' => 'House 110, Sector 47', 'zip' => '110001']);

        $addr = $order->fresh()->shipping_address;
        $this->assertSame('House 110, Sector 47', $addr['street_address']);
        $this->assertSame('110001', $addr['zip']);
        $this->assertSame('New Delhi', $addr['city'], 'untouched fields stay');
        $this->assertSame(['lat' => 28.6, 'lng' => 77.2], $addr['location'], 'the GPS pin must survive a text-only edit');
    }

    public function test_pin_nudge_preserves_the_typed_street(): void
    {
        $order = $this->makeOrder();

        $this->saveAddr($order, ['location' => ['lat' => 28.61, 'lng' => 77.21]]);

        $addr = $order->fresh()->shipping_address;
        $this->assertSame(['lat' => 28.61, 'lng' => 77.21], $addr['location']);
        $this->assertSame('House 109, Sector 47', $addr['street_address'], 'the typed street must survive a pin nudge');
        $this->assertSame('110004', $addr['zip']);
    }
}
