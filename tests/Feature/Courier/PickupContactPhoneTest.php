<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Services\Courier\ShippingServiceClient;
use Tests\TestCase;

/**
 * The pickup phone a same-city booking is refused without.
 *
 * A vendor onboarded through the admin form reliably HAS a phone — in `shops.mobile`, the column
 * the vendor form writes and the one VendorResource treats as the vendor phone of record. The
 * courier path read `address.phone` then `settings.contact` and never `mobile`, so whether a
 * booking succeeded came down to whether someone happened to fill an OPTIONAL settings box. On
 * staging that was the entire difference between two identical vendors: one booked, one returned
 * "invalid shipment request: pickup has no contact phone" from the Go validator, which requires a
 * pickup phone on the hyperlocal lane (and only there).
 */
final class PickupContactPhoneTest extends TestCase
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

        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('mobile')->nullable();
            $t->text('address')->nullable();
            $t->text('settings')->nullable();
            $t->string('pickup_postcode')->nullable();
            $t->timestamps();
        });
    }

    private function shop(array $attrs): object
    {
        $id = DB::table('shops')->insertGetId($attrs + ['name' => 'Vendor']);
        return \Marvel\Database\Models\Shop::find($id);
    }

    private function phoneFor(object $shop): string
    {
        return (string) ((new ShippingServiceClient())->pickupAddressOf($shop, null)['phone'] ?? '');
    }

    public function test_it_falls_back_to_the_vendors_mobile(): void
    {
        // The staging case verbatim: mobile set, settings.contact absent.
        $shop = $this->shop(['mobile' => '8168355309', 'address' => json_encode(['city' => 'Delhi'])]);

        $this->assertSame('8168355309', $this->phoneFor($shop), 'shops.mobile must be used when nothing else has a phone');
    }

    public function test_an_address_phone_still_wins(): void
    {
        $shop = $this->shop([
            'mobile'  => '8168355309',
            'address' => json_encode(['city' => 'Delhi', 'phone' => '9990001111']),
        ]);

        $this->assertSame('9990001111', $this->phoneFor($shop), 'an explicit address phone outranks the profile mobile');
    }

    public function test_settings_contact_still_works_when_there_is_no_mobile(): void
    {
        // The other staging vendor. Must keep working — this is the path that already booked.
        $shop = $this->shop(['settings' => json_encode(['contact' => '919996469046'])]);

        $this->assertSame('919996469046', $this->phoneFor($shop));
    }

    public function test_an_empty_address_phone_falls_through_rather_than_winning(): void
    {
        // The original chain used `??`, which stops at a present-but-empty string and never reaches
        // the fallback at all — so a blank phone key silently defeated every later source.
        $shop = $this->shop([
            'mobile'  => '8168355309',
            'address' => json_encode(['city' => 'Delhi', 'phone' => '']),
        ]);

        $this->assertSame('8168355309', $this->phoneFor($shop), 'an empty phone must fall through, not win');
    }

    public function test_a_vendor_with_no_phone_anywhere_yields_empty(): void
    {
        // Fails closed — the guard in CourierService turns this into an actionable refusal rather
        // than letting the partner reject it.
        $shop = $this->shop(['address' => json_encode(['city' => 'Delhi'])]);

        $this->assertSame('', $this->phoneFor($shop));
    }
}
