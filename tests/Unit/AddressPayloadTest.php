<?php

declare(strict_types=1);

namespace Tests\Unit;

use Marvel\Database\Models\Address;
use PHPUnit\Framework\TestCase;

/**
 * Address::sanitizePayload = the ONE whitelist every address writer routes
 * through (the model has $guarded = [], so an unwhitelisted payload is an
 * arbitrary-column-injection hole). Address::missingFields = the
 * complete-on-use gate shared by checkout complete-on-use and the courier
 * dispatch pre-flight.
 */
final class AddressPayloadTest extends TestCase
{
    public function test_sanitize_strips_table_junk_and_server_owned_fields(): void
    {
        $out = Address::sanitizePayload([
            'id'          => 999,
            'customer_id' => 7,
            'created_at'  => 'x',
            'rg_city'     => 'Spoofed',
            'is_admin'    => true,          // arbitrary column injection attempt
            'title'       => 'Home',
            'type'        => 'shipping',
            'latitude'    => 28.6,
            'longitude'   => 77.2,
        ]);

        $this->assertArrayNotHasKey('id', $out);
        $this->assertArrayNotHasKey('customer_id', $out);
        $this->assertArrayNotHasKey('rg_city', $out);
        $this->assertArrayNotHasKey('is_admin', $out);
        $this->assertSame('Home', $out['title']);
        $this->assertSame(28.6, $out['latitude']);
    }

    public function test_sanitize_maps_legacy_aliases_and_whitelists_json_keys(): void
    {
        $out = Address::sanitizePayload([
            'address' => [
                'street_address' => 'Ring Road',
                'pincode'        => '110 001',   // legacy alias + stray space
                'apartment'      => 'B-42',      // legacy alias for house_no
                'evil_key'       => 'x',
                'city'           => 'Delhi',
            ],
        ]);

        $this->assertSame('110001', $out['address']['zip']);
        $this->assertSame('B-42', $out['address']['house_no']);
        $this->assertArrayNotHasKey('evil_key', $out['address']);
        $this->assertArrayNotHasKey('pincode', $out['address']);
    }

    public function test_sanitize_drops_invalid_coords_and_address_type(): void
    {
        $out = Address::sanitizePayload([
            'latitude'     => 'not-a-number',
            'address_type' => 'castle',
            'default'      => '1',
        ]);

        $this->assertArrayNotHasKey('latitude', $out);
        $this->assertArrayNotHasKey('address_type', $out);
        $this->assertTrue($out['default']);
    }

    public function test_missing_fields_gate_street_city_state_zip(): void
    {
        $this->assertSame(
            ['street_address', 'city', 'state', 'zip'],
            Address::missingFields([])
        );
        $this->assertSame([], Address::missingFields([
            'street_address' => 'E-512 Greater Kailash', 'city' => 'Delhi', 'state' => 'Delhi', 'zip' => '110048',
        ]));
    }

    public function test_missing_fields_tolerates_snapshot_nesting_and_zip_aliases(): void
    {
        // Order snapshots sometimes nest under 'address' and use legacy zip keys.
        $this->assertSame([], Address::missingFields([
            'address' => ['street_address' => '1 Lane', 'city' => 'Delhi', 'state' => 'DL', 'pincode' => '110001'],
        ]));
    }

    public function test_missing_fields_rejects_invalid_pin(): void
    {
        $missing = Address::missingFields([
            'street_address' => '1 Lane', 'city' => 'Delhi', 'state' => 'DL', 'zip' => '0001',
        ]);

        $this->assertSame(['zip'], $missing);
    }
}
