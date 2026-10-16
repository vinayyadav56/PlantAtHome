<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use App\Modules\Nursery\Http\Requests\UpsertNurseryRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\VendorPickupLocation;
use Marvel\Database\Repositories\ShopRepository;
use Marvel\Http\Requests\ShopCreateRequest;
use Marvel\Services\Courier\CourierService;
use Marvel\Services\Courier\ShippingServiceClient;
use Tests\TestCase;

/**
 * Owner rule (2026-10-16): a partner nursery's pickup address comes from its registration in the
 * admin — nobody provides or registers it separately. PlantAtHome's own warehouses stay manual.
 */
final class VendorRegistrationPickupTest extends TestCase
{
    /** What the Shiprocket stub answers for the partner's runtime config. */
    private bool $shiprocketOn = true;

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
            $t->text('description')->nullable();
            $t->text('cover_image')->nullable();
            $t->text('logo')->nullable();
            $t->boolean('is_active')->default(false);
            $t->text('address')->nullable();
            $t->text('settings')->nullable();
            $t->string('contact_person')->nullable();
            $t->string('mobile')->nullable();
            $t->string('gst_number')->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->string('pickup_location_name')->nullable();
            $t->string('pickup_postcode')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
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
            $t->boolean('auto_register')->default(false);
            $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
        DB::table('settings')->insert(['id' => 1, 'language' => 'en', 'options' => json_encode(['courier' => ['enabled' => true]])]);

        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/v1/partners/shiprocket/config')) {
                return Http::response(['code' => 'shiprocket', 'configured' => true, 'enabled' => $this->shiprocketOn]);
            }
            if (str_ends_with($request->url(), '/v1/partners/shiprocket/register-pickup')) {
                return Http::response(['code' => 'shiprocket', 'ok' => true, 'outcome' => 'registered (pickup_code 777)', 'pickup_code' => '777']);
            }
            return Http::response([], 404);
        });
    }

    /** The admin's registration form, as ShopRepository::storeShop receives it. */
    private function register(array $overrides = []): Shop
    {
        $request = Request::create('/shops', 'POST', array_replace_recursive([
            'name'    => 'Green Leaf Nursery',
            'mobile'  => '9876543210',
            'address' => [
                'house_no' => 'Plot 12', 'street_address' => 'MG Road', 'area' => 'Camp',
                'city' => 'Pune', 'state' => 'Maharashtra', 'zip' => '411001', 'country' => 'India',
            ],
        ], $overrides));
        $request->setUserResolver(fn () => new class {
            public int $id = 1;

            public function hasPermissionTo($permission): bool
            {
                return true; // super admin
            }
        });

        return app(ShopRepository::class)->storeShop($request);
    }

    private function door(Shop $shop): VendorPickupLocation
    {
        return VendorPickupLocation::where('shop_id', $shop->id)->sole();
    }

    private function pickup(Shop $shop): array
    {
        $shop = $shop->fresh();
        return (new ShippingServiceClient())->pickupAddressOf($shop, $this->door($shop));
    }

    public function test_registering_a_nursery_opens_its_pickup_from_the_registration_address(): void
    {
        $shop = $this->register();

        $door = $this->door($shop);
        $this->assertTrue($door->is_default);
        $this->assertTrue($door->auto_register);
        $this->assertSame('pending', $door->status);
        $this->assertNull($door->address, 'no copy: the pickup reads the nursery profile itself');

        $pickup = $this->pickup($shop);
        $this->assertSame('Plot 12, MG Road', $pickup['address']);
        $this->assertSame('Pune', $pickup['city']);
        $this->assertSame('Maharashtra', $pickup['state']);
        $this->assertSame('411001', $pickup['pincode']);
        $this->assertSame('9876543210', $pickup['phone']);
        $this->assertSame([], app(CourierService::class)->missingPickupFields($shop->fresh(), $door));
    }

    public function test_profile_edits_flow_into_the_pickup_until_shiprocket_holds_it(): void
    {
        $shop = $this->register();
        $shop->update(['address' => ['house_no' => '7', 'street_address' => 'FC Road', 'city' => 'Pune', 'state' => 'Maharashtra', 'zip' => '411004']]);
        $this->assertSame('7, FC Road', $this->pickup($shop)['address']);
        $this->assertSame('411004', $this->pickup($shop)['pincode']);

        $res = app(CourierService::class)->syncPickupLocation($shop->fresh(), $this->door($shop));
        $this->assertTrue($res['ok']);
        $door = $this->door($shop);
        $this->assertSame('verified', $door->status);
        $this->assertSame('shop-' . $shop->id, $door->provider_location_name);
        $this->assertSame('777', $door->provider_pickup_code);
        $this->assertSame('7, FC Road', $door->address, 'the row now says what Shiprocket holds');
        $this->assertSame('411004', $door->pincode);

        // Shiprocket cannot update a registered address, so a later edit must not move the pickup.
        $shop->update(['address' => ['house_no' => '1', 'street_address' => 'Elsewhere', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'zip' => '400001']]);
        $this->assertSame('7, FC Road', $this->pickup($shop)['address']);
        $this->assertSame('411004', $this->pickup($shop)['pincode']);
    }

    public function test_the_sweep_registers_approved_nurseries_only_and_waits_while_shiprocket_is_off(): void
    {
        $live = $this->register(['name' => 'Live']);
        $unapproved = $this->register(['name' => 'Unapproved', 'mobile' => '9876543211']);
        $warehouses = $this->register(['name' => 'Warehouses', 'mobile' => '9876543212']);
        $refused = $this->register(['name' => 'Refused', 'mobile' => '9876543213']);
        Shop::whereIn('id', [$live->id, $warehouses->id, $refused->id])->update(['is_active' => true]);
        $warehouses->forceFill(['settings' => ['pickup_choice' => 'staff']])->save();
        $this->door($refused)->forceFill(['status' => 'failed', 'last_error' => 'bad phone'])->save();
        // An existing vendor's backfilled door: still registered by a person, as before.
        $old = Shop::create(['name' => 'Old', 'is_active' => true, 'address' => ['street_address' => '1 Lane', 'city' => 'Pune', 'state' => 'Maharashtra', 'zip' => '411001']]);
        VendorPickupLocation::create(['shop_id' => $old->id, 'label' => 'Primary', 'is_default' => true]);

        $this->shiprocketOn = false;
        $res = app(CourierService::class)->registerVendorPickups();
        $this->assertSame('shiprocket off', $res['waiting']);
        $this->assertSame(1, $res['pending']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'register-pickup'));
        $this->assertSame('pending', $this->door($live)->status);

        $this->shiprocketOn = true;
        $this->assertSame(['registered' => 1, 'failed' => 0], app(CourierService::class)->registerVendorPickups());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'register-pickup') && $r->data()['name'] === 'shop-' . $live->id);
        Http::assertSentCount(3); // config (off), config (on), one registration
        $this->assertSame('verified', $this->door($live)->status);
        $this->assertSame('pending', $this->door($unapproved)->status, 'not live yet');
        $this->assertSame('pending', $this->door($warehouses)->status, "PlantAtHome's warehouses stay manual");
        $this->assertSame('failed', $this->door($refused)->status, 'a refusal waits for a person');
        $this->assertSame('pending', $this->door($old)->status);

        $this->assertSame(['registered' => 0, 'failed' => 0], app(CourierService::class)->registerVendorPickups());
        Http::assertSentCount(3); // nothing left to do: no partner call at all
    }

    public function test_registration_needs_a_house_number_and_a_phone_on_both_admin_paths(): void
    {
        $missing = ['name' => 'N', 'address' => ['street_address' => 'MG Road', 'city' => 'Pune', 'state' => 'Maharashtra', 'zip' => '411001']];

        $legacy = Validator::make($missing, (new ShopCreateRequest())->rules());
        $this->assertTrue($legacy->errors()->has('address.house_no'));
        $this->assertTrue($legacy->errors()->has('mobile'));

        $v2 = Validator::make($missing, UpsertNurseryRequest::create('/api/v1/nurseries', 'POST')->rules());
        $this->assertTrue($v2->errors()->has('address.house_no'));
        $this->assertTrue($v2->errors()->has('mobile'));

        // Editing an existing nursery is unchanged.
        $edit = Validator::make(['name' => 'N'], UpsertNurseryRequest::create('/api/v1/nurseries/x', 'PUT')->rules());
        $this->assertFalse($edit->fails());
    }
}
