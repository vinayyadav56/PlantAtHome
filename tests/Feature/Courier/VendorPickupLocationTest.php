<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Http\Controllers\CourierShipmentController;
use Marvel\Database\Models\Shipment;
use Marvel\Database\Models\VendorPickupLocation;
use Marvel\Services\Courier\CourierService;
use Tests\TestCase;

/**
 * The two things a courier booking must not get wrong, both money paths:
 *
 *  - WHERE it leaves from. A nickname the partner never accepted 422s the booking, and a door
 *    belonging to a different vendor sends a courier to the wrong yard. Resolution is per
 *    SHIPMENT (its own door → its vendor's default → the legacy shops.pickup_location_name,
 *    which must keep working with no backfill).
 *  - WHO carries it. The courier id arrives from the admin UI, so it is re-validated against a
 *    fresh quote before anything is persisted or booked.
 */
final class VendorPickupLocationTest extends TestCase
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
            $t->unsignedBigInteger('pickup_location_id')->nullable();
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
            $t->string('provider_shipment_id')->nullable();
            $t->string('awb_number')->nullable();
            $t->string('courier_name')->nullable();
            $t->unsignedInteger('courier_company_id')->nullable();
            $t->string('tracking_url')->nullable();
            $t->string('payment_method')->nullable();
            $t->decimal('booked_cost')->nullable();
            $t->string('last_status')->nullable();
            $t->timestamp('last_status_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancelled_reason')->nullable();
            $t->unsignedTinyInteger('simulation_flow_type')->nullable();
            $t->timestamp('simulation_started_at')->nullable();
            $t->string('failure_reason')->nullable();
            $t->timestamps();
        });
        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('slug')->nullable(); // the Shop model slugs itself on save
            $t->text('address')->nullable();
            $t->text('settings')->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->string('pickup_location_name')->nullable();
            $t->string('pickup_postcode')->nullable();
            $t->timestamps();
        });
        Schema::create('vendor_pickup_locations', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id');
            $t->string('label', 64);
            $t->string('contact_name', 96)->nullable();
            $t->string('phone', 24)->nullable();
            $t->string('address', 255)->nullable();
            $t->string('address_2', 255)->nullable();
            $t->string('city', 96)->nullable();
            $t->string('state', 96)->nullable();
            $t->string('country', 64)->default('India');
            $t->string('pincode', 12)->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->string('partner', 24)->default('shiprocket');
            $t->string('provider_location_name', 64)->nullable();
            $t->string('provider_pickup_code', 64)->nullable();
            $t->string('status', 16)->default('pending');
            $t->timestamp('last_synced_at')->nullable();
            $t->string('last_error', 255)->nullable();
            $t->boolean('is_default')->default(false);
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
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->timestamps();
        });
        // Order eager-loads its products pivot on every read.
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

        // Courier master switch — without it every call returns early and the assertions below
        // would pass against zero requests.
        DB::table('settings')->insert([
            'id' => 1, 'language' => 'en',
            'options' => json_encode(['courier' => ['enabled' => true]]),
        ]);
    }

    private function shop(int $id, array $attrs = []): void
    {
        DB::table('shops')->insert(array_merge([
            'id'      => $id,
            'name'    => "Vendor {$id}",
            'address' => json_encode([
                'street_address' => '1 Garden Lane', 'city' => 'Delhi', 'state' => 'Delhi',
                'zip' => '110001', 'phone' => '9876543210',
            ]),
        ], $attrs));
    }

    /** status + provider_location_name are the PARTNER's answer, so they are not mass-assignable. */
    private function location(int $shopId, array $attrs = []): VendorPickupLocation
    {
        $partnerFields = ['status', 'provider_location_name', 'provider_pickup_code'];
        $location = VendorPickupLocation::create(array_merge([
            'shop_id' => $shopId, 'label' => 'Primary', 'contact_name' => "Vendor {$shopId}",
            'phone' => '9876543210', 'address' => '1 Garden Lane', 'city' => 'Delhi',
            'state' => 'Delhi', 'pincode' => '110001', 'is_default' => true,
        ], array_diff_key($attrs, array_flip($partnerFields))));

        $location->forceFill([
            'status'                 => $attrs['status'] ?? 'pending',
            'provider_location_name' => $attrs['provider_location_name'] ?? null,
        ])->save();

        return $location;
    }

    private function shipment(int $shopId, array $attrs = []): Shipment
    {
        $order = Order::create([
            'tracking_number'  => 'T' . random_int(10000000, 99999999),
            'shipping_address' => json_encode(['street_address' => '2 Park Rd', 'city' => 'Delhi', 'state' => 'Delhi', 'zip' => '110002']),
        ]);
        return Shipment::create(array_merge([
            'order_id' => $order->id, 'shop_id' => $shopId,
            'fulfillment_mode' => 'courier', 'mode' => 'courier', 'status' => 'pending',
        ], $attrs));
    }

    /** Rates as the Go service returns them: one row PER COURIER, each with its own id. */
    private function fakeQuotes(array $couriers = [[51, 'Delhivery Surface', 90.0], [12, 'Bluedart Air', 140.0]]): void
    {
        Http::fake([
            '*/v1/quotes' => Http::response([
                'mode'   => 'courier',
                'quotes' => array_map(static fn ($c) => [
                    'partner' => 'shiprocket', 'cost' => $c[2],
                    'courier_id' => $c[0], 'courier_name' => $c[1],
                ], $couriers),
            ], 200),
        ]);
    }

    // ── courier selection ────────────────────────────────────────────────

    public function test_a_quoted_courier_is_accepted_and_persisted(): void
    {
        $this->shop(5);
        $this->location(5, ['status' => 'verified', 'provider_location_name' => 'shop-5']);
        $shipment = $this->shipment(5);
        $this->fakeQuotes();

        $res = (new CourierService())->chooseCourier($shipment, 51);

        $this->assertTrue($res['ok']);
        $this->assertSame(51, (int) $shipment->fresh()->courier_company_id);
        $this->assertSame('Delhivery Surface', $shipment->fresh()->courier_name);
    }

    public function test_a_courier_the_partner_never_offered_is_refused(): void
    {
        // The whole point of re-quoting: the id comes off a client, so it can name a courier that
        // is cheaper on a stale screen and unavailable on this lane right now.
        $this->shop(5);
        $this->location(5, ['status' => 'verified', 'provider_location_name' => 'shop-5']);
        $shipment = $this->shipment(5);
        $this->fakeQuotes();

        $res = (new CourierService())->chooseCourier($shipment, 999);

        $this->assertFalse($res['ok']);
        $this->assertSame([51, 12], $res['offered']);
        $this->assertNull($shipment->fresh()->courier_company_id, 'an unquoted courier must not be persisted');
    }

    public function test_the_chosen_courier_travels_on_the_booking_that_validated_it(): void
    {
        $this->shop(5);
        $this->location(5, ['status' => 'verified', 'provider_location_name' => 'shop-5']);
        $shipment = $this->shipment(5);
        $this->fakeQuotes();
        $chosen = (new CourierService())->chooseCourier($shipment, 51);

        Http::fake(['*/v1/shipments' => Http::response(['partner' => 'shiprocket', 'status' => 'assigned'], 200)]);
        // The validated id rides the booking as an ARGUMENT. It is deliberately no longer read
        // back off shipments.courier_company_id, which is never cleared and so replayed a
        // day-old courier against a fresh rate card on every later rebook or auto-book.
        // See CourierOperationsTest for the replay case itself.
        (new CourierService())->book($shipment->fresh(), null, $chosen['courier_id']);

        Http::assertSent(fn ($request) => (int) ($request->data()['courier_id'] ?? 0) === 51);
    }

    // ── pickup resolution ────────────────────────────────────────────────

    // ── §67/§68 marketplace: origin recorded, and reused ─────────────────────────────────────

    /**
     * §68. Booking twice from the same door must reuse the SAME registered location, never
     * register a second one — the duplicate-protection the spec asks for is the unique key
     * (shop_id, partner, label) plus resolving through the existing row.
     */
    public function test_two_shipments_from_one_vendor_share_one_registered_door(): void
    {
        $this->shop(5);
        $door = $this->location(5, ['status' => 'verified', 'provider_location_name' => 'shop-5']);

        $a = $this->shipment(5);
        $b = $this->shipment(5);

        $courier = new CourierService();
        $this->assertSame($door->id, $courier->pickupLocationFor($a)->id);
        $this->assertSame($door->id, $courier->pickupLocationFor($b)->id);
        $this->assertSame(
            1,
            VendorPickupLocation::where('shop_id', 5)->count(),
            'a second shipment registered a second door instead of reusing the first',
        );
    }

    /**
     * §67. Each vendor's leg resolves to that vendor's OWN door. The rule that makes a
     * three-vendor order into three correctly-addressed shipments is the same one tested here at
     * two: resolution is scoped to the shipment's shop, never to whichever door was registered
     * most recently.
     */
    public function test_each_vendors_leg_resolves_to_its_own_door(): void
    {
        $this->shop(5);
        $this->shop(6);
        $doorA = $this->location(5, ['status' => 'verified', 'provider_location_name' => 'shop-5']);
        $doorB = $this->location(6, ['status' => 'verified', 'provider_location_name' => 'shop-6']);

        $courier = new CourierService();
        $this->assertSame($doorA->id, $courier->pickupLocationFor($this->shipment(5))->id);
        $this->assertSame($doorB->id, $courier->pickupLocationFor($this->shipment(6))->id);
        $this->assertSame('shop-5', $courier->pickupNameFor($this->shipment(5)));
        $this->assertSame('shop-6', $courier->pickupNameFor($this->shipment(6)));
    }

    // ── needs-sync ───────────────────────────────────────────────────────────────────────────

    /**
     * Shiprocket has no update-pickup API: a registered door keeps whatever address it was
     * registered with. Leaving the row `verified` after a local address edit meant the admin
     * showed a green badge over an address the partner had never seen, and every booking left
     * from the old yard. Demoting to `pending` is what makes that visible.
     */
    public function test_editing_a_registered_doors_address_demotes_it_to_pending(): void
    {
        $this->shop(5);
        $door = $this->location(5, ['status' => 'verified', 'provider_location_name' => 'shop-5']);

        $req = Request::create('/x', 'PUT', [
            'shop_id' => 5,
            'label'   => $door->label,
            'address' => 'A COMPLETELY DIFFERENT YARD',
            'city'    => $door->city,
            'state'   => $door->state,
            'pincode' => $door->pincode,
            'phone'   => $door->phone,
        ]);
        (new CourierShipmentController())->updatePickupLocation($req, $door->id);

        $fresh = $door->fresh();
        $this->assertSame('pending', $fresh->status, 'a moved door still claims the partner knows about it');
        $this->assertNotEmpty($fresh->last_error, 'nothing tells the operator why it went pending');
        $this->assertSame('shop-5', $fresh->provider_location_name, 'the registered nickname must survive — it is the partner key');
    }

    public function test_editing_only_the_contact_leaves_a_verified_door_alone(): void
    {
        $this->shop(5);
        $door = $this->location(5, ['status' => 'verified', 'provider_location_name' => 'shop-5']);

        $req = Request::create('/x', 'PUT', [
            'shop_id'      => 5,
            'label'        => $door->label,
            'address'      => $door->address,
            'city'         => $door->city,
            'state'        => $door->state,
            'pincode'      => $door->pincode,
            'phone'        => $door->phone,
            'contact_name' => 'A New Person',
        ]);
        (new CourierShipmentController())->updatePickupLocation($req, $door->id);

        $this->assertSame(
            'verified',
            $door->fresh()->status,
            'the partner keys on the ADDRESS — a contact change must not force a re-registration',
        );
    }

    public function test_pickup_resolution_stays_inside_the_shipments_own_vendor(): void
    {
        $this->shop(5);
        $this->shop(6);
        $this->location(5, ['status' => 'verified', 'provider_location_name' => 'shop-5']);
        $otherVendorsDoor = $this->location(6, ['status' => 'verified', 'provider_location_name' => 'shop-6']);

        // A stale (or hand-edited) id pointing at ANOTHER vendor's yard must never win.
        $shipment = $this->shipment(5, ['pickup_location_id' => $otherVendorsDoor->id]);

        $courier = new CourierService();
        $this->assertSame(5, (int) $courier->pickupLocationFor($shipment)->shop_id);
        $this->assertSame('shop-5', $courier->pickupNameFor($shipment));
    }

    public function test_the_shipments_own_door_wins_over_the_vendor_default(): void
    {
        $this->shop(5);
        $this->location(5, ['status' => 'verified', 'provider_location_name' => 'shop-5']);
        $secondYard = $this->location(5, [
            'label' => 'Back Yard', 'is_default' => false,
            'status' => 'verified', 'provider_location_name' => 'shop-5-2',
        ]);

        $shipment = $this->shipment(5, ['pickup_location_id' => $secondYard->id]);

        $this->assertSame('shop-5-2', (new CourierService())->pickupNameFor($shipment));
    }

    public function test_the_legacy_shop_nickname_still_answers_without_any_rows(): void
    {
        // No backfill required: a vendor registered before this table existed keeps booking.
        $this->shop(7, ['pickup_location_name' => 'shop-7']);
        $shipment = $this->shipment(7);

        $courier = new CourierService();
        $this->assertNull($courier->pickupLocationFor($shipment));
        $this->assertSame('shop-7', $courier->pickupNameFor($shipment));
        $this->assertNull($courier->pickupBlocker($shipment));
    }

    public function test_a_vendor_whose_door_was_never_accepted_cannot_book(): void
    {
        $this->shop(8, ['pickup_location_name' => 'shop-8']); // legacy stamp from an older deploy
        $this->location(8, ['status' => 'pending']);          // …but the real state is: not registered
        $shipment = $this->shipment(8);

        Http::fake(['*' => Http::response(['partner' => 'shiprocket'], 200)]);
        $res = (new CourierService())->book($shipment);

        $this->assertFalse($res['ok']);
        $this->assertSame('PICKUP_NOT_REGISTERED', $res['code']);
        Http::assertNothingSent();
    }

    // ── registration writes the row ──────────────────────────────────────

    public function test_registration_records_the_partners_answer_on_the_row(): void
    {
        $this->shop(5);
        $location = $this->location(5);
        Http::fake(['*/register-pickup' => Http::response([
            'code' => 'shiprocket', 'ok' => true, 'outcome' => 'registered', 'pickup_code' => 'SR-7788',
        ], 200)]);

        $res = (new CourierService())->syncPickupLocation(\Marvel\Database\Models\Shop::find(5), $location);

        $this->assertTrue($res['ok']);
        $fresh = $location->fresh();
        $this->assertSame('verified', $fresh->status);
        $this->assertSame('shop-5', $fresh->provider_location_name);
        $this->assertSame('SR-7788', $fresh->provider_pickup_code);
        $this->assertNotNull($fresh->last_synced_at);
        // Legacy column still stamped: other flows read it and nothing was migrated.
        $this->assertSame('shop-5', \Marvel\Database\Models\Shop::find(5)->pickup_location_name);
    }

    public function test_a_failed_registration_is_recorded_as_failed(): void
    {
        $this->shop(5);
        $location = $this->location(5);
        Http::fake(['*/register-pickup' => Http::response([
            'code' => 'shiprocket', 'ok' => false, 'error' => 'shiprocket: Please provide a valid phone number (422)',
        ], 200)]);

        $res = (new CourierService())->syncPickupLocation(\Marvel\Database\Models\Shop::find(5), $location);

        $this->assertFalse($res['ok']);
        $fresh = $location->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('valid phone', (string) $fresh->last_error);
        $this->assertNull(\Marvel\Database\Models\Shop::find(5)->pickup_location_name);
    }
}
