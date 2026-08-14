<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed one 'Primary' pickup location per vendor from the address the courier stack
 * already sends, so nothing has to be re-entered and every existing booking path keeps
 * resolving to the same door it used yesterday.
 *
 * status is 'verified' only when shops.pickup_location_name is set — that nickname is
 * written exclusively after Shiprocket accepted the registration, so it is the one
 * piece of evidence we have that the location really exists at the partner. Everyone
 * else starts 'pending' and must be registered.
 *
 * Idempotent: insertOrIgnore against the (shop_id, partner, label) unique index, so a
 * re-run never duplicates and never clobbers an operator's later edits. Chunked plain
 * PHP so MySQL (prod) and sqlite (tests) behave identically.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('vendor_pickup_locations') || !Schema::hasTable('shops')) {
            return;
        }

        $cols = ['id', 'name', 'address', 'settings', 'pickup_location_name', 'pickup_postcode'];
        $hasCoords = Schema::hasColumn('shops', 'lat') && Schema::hasColumn('shops', 'lng');
        if ($hasCoords) {
            $cols[] = 'lat';
            $cols[] = 'lng';
        }

        $now = now();

        DB::table('shops')->select($cols)->orderBy('id')->chunkById(200, function ($shops) use ($hasCoords, $now) {
            $rows = [];
            foreach ($shops as $shop) {
                $a = $this->decode($shop->address);
                $settings = $this->decode($shop->settings);
                $loc = (array) ($settings['location'] ?? []);

                $street = $this->pick($a, ['street_address', 'address']);
                $pincode = trim((string) ($shop->pickup_postcode ?? '')) ?: $this->pick($a, ['zip', 'pincode']);

                // No street and no pin = nothing a courier could be sent to. Registering that
                // would only manufacture a row that can never reach 'verified'.
                if ($street === '' && $pincode === '') {
                    continue;
                }

                $nickname = trim((string) ($shop->pickup_location_name ?? ''));
                $lat = $this->pick($a, ['lat', 'latitude']) ?: (string) ($hasCoords ? ($shop->lat ?? '') : '') ?: (string) ($loc['lat'] ?? '');
                $lng = $this->pick($a, ['lng', 'longitude']) ?: (string) ($hasCoords ? ($shop->lng ?? '') : '') ?: (string) ($loc['lng'] ?? '');

                $rows[] = [
                    'shop_id'                => $shop->id,
                    'label'                  => 'Primary',
                    'contact_name'           => mb_substr((string) ($shop->name ?: 'Vendor'), 0, 96),
                    'phone'                  => mb_substr($this->pick($a, ['phone']) ?: (string) ($settings['contact'] ?? ''), 0, 24) ?: null,
                    'address'                => mb_substr($street, 0, 255) ?: null,
                    'address_2'              => mb_substr($this->pick($a, ['line2', 'street_address2', 'address_line_2', 'area', 'locality']), 0, 255) ?: null,
                    'city'                   => mb_substr($this->pick($a, ['city']), 0, 96) ?: null,
                    'state'                  => mb_substr($this->pick($a, ['state']), 0, 96) ?: null,
                    'country'                => 'India',
                    'pincode'                => mb_substr($pincode, 0, 12) ?: null,
                    'lat'                    => is_numeric($lat) ? (float) $lat : null,
                    'lng'                    => is_numeric($lng) ? (float) $lng : null,
                    'partner'                => 'shiprocket',
                    'provider_location_name' => $nickname ?: null,
                    'provider_pickup_code'   => null,
                    'status'                 => $nickname !== '' ? 'verified' : 'pending',
                    'last_synced_at'         => null,
                    'last_error'             => null,
                    'is_default'             => true,
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ];
            }

            if ($rows) {
                DB::table('vendor_pickup_locations')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        // Backfill only — the table is dropped by its own migration.
    }

    private function decode($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** First non-empty of $keys, trimmed; '' when none. Vendor addresses use several key spellings. */
    private function pick(array $src, array $keys): string
    {
        foreach ($keys as $k) {
            $v = trim((string) ($src[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }
};
