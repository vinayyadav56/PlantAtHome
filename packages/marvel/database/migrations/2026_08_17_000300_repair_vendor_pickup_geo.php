<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Services\LocationNormalizer;

/**
 * Repair vendor pickup geography: coordinates that contradict their own pincode, and the two
 * city columns the canonical-city pass missed.
 *
 * THE COORDINATES. A vendor's pin is what every hyperlocal partner routes on. Shop 32's sat at
 * 28.1867, 76.3536 — Rewari, 95km from the "New Delhi 110048" address on the same record — and
 * that single wrong number made Porter refuse every booking with `restricted_location` and
 * Shiprocket Quick answer "No Courier Serviceable", because neither will run a 95km leg as a
 * same-city delivery. Nothing was wrong with the code; it was faithfully asking couriers to drive
 * to Rewari.
 *
 * The repair is deliberately conservative and self-limiting:
 *   - only rows whose pincode HAS a known centroid in our own postal master (19,238 rows);
 *   - only when the stored pin is more than THRESHOLD_KM from it, which no legitimate pin can be:
 *     an Indian pincode spans a few kilometres, so 25km is already far past plausible;
 *   - the street address, contact and pincode are NEVER touched. Only the broken coordinate moves,
 *     to the centroid of the pincode the vendor themselves entered.
 *
 * ⚠️ A centroid is not the doorstep. It is the right answer for routing at city scale and the
 * wrong one for a rider's last 200m — a vendor whose pin is corrected here should still re-drop it
 * accurately in the admin. It is strictly better than a pin in another district.
 */
return new class extends Migration
{
    private const AUDIT = 'location_backfill_2026_08';
    private const THRESHOLD_KM = 25.0;

    public function up(): void
    {
        $this->repairCoordinates();
        $this->normalizeCities();
    }

    public function down(): void
    {
        // Reversal runs through the shared audit table (see 000300_normalize_stored_city_district).
    }

    /** Haversine, kilometres. */
    private function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function repairCoordinates(): void
    {
        if (!Schema::hasTable('postal_codes') || !Schema::hasTable('vendor_pickup_locations')) {
            return;
        }

        $centroids = [];
        foreach (
            DB::table('postal_codes')->whereNotNull('latitude')->whereNotNull('longitude')
                ->get(['pincode', 'latitude', 'longitude']) as $pc
        ) {
            $centroids[trim((string) $pc->pincode)] ??= [(float) $pc->latitude, (float) $pc->longitude];
        }

        foreach (DB::table('vendor_pickup_locations')->get(['id', 'shop_id', 'pincode', 'lat', 'lng']) as $row) {
            $pin = trim((string) $row->pincode);
            if ($pin === '' || !isset($centroids[$pin])) {
                continue;
            }
            [$cLat, $cLng] = $centroids[$pin];
            $lat  = (float) $row->lat;
            $lng  = (float) $row->lng;

            // A 0,0 pin is the monolith's fallback sentinel and is always wrong; anything else is
            // judged on distance from the pincode it claims to be in.
            $broken = ($lat === 0.0 && $lng === 0.0)
                || $this->km($lat, $lng, $cLat, $cLng) > self::THRESHOLD_KM;

            if (!$broken) {
                continue;
            }

            $this->audit('vendor_pickup_locations', (int) $row->id, 'lat', (string) $row->lat);
            $this->audit('vendor_pickup_locations', (int) $row->id, 'lng', (string) $row->lng);
            DB::table('vendor_pickup_locations')->where('id', $row->id)
                ->update(['lat' => $cLat, 'lng' => $cLng, 'updated_at' => now()]);

            // The shop row is the fallback the pickup row overrides — leaving it wrong means the
            // next vendor without a pickup row inherits the same broken pin.
            if (Schema::hasColumn('shops', 'lat')) {
                $shop = DB::table('shops')->where('id', $row->shop_id)->first(['lat', 'lng']);
                if ($shop && $this->km((float) $shop->lat, (float) $shop->lng, $cLat, $cLng) > self::THRESHOLD_KM) {
                    $this->audit('shops', (int) $row->shop_id, 'lat', (string) $shop->lat);
                    $this->audit('shops', (int) $row->shop_id, 'lng', (string) $shop->lng);
                    DB::table('shops')->where('id', $row->shop_id)->update(['lat' => $cLat, 'lng' => $cLng]);
                }
            }
        }
    }

    /**
     * The canonical-city pass normalized addresses, orders, users and carts but missed these two:
     * both still read "New Delhi", a district standing in for the city.
     */
    private function normalizeCities(): void
    {
        LocationNormalizer::flush();
        $n = new LocationNormalizer();

        if (Schema::hasTable('vendor_pickup_locations')) {
            foreach (DB::table('vendor_pickup_locations')->get(['id', 'city', 'state', 'pincode']) as $row) {
                $res = $n->normalize(['city' => $row->city, 'state' => $row->state, 'zip' => $row->pincode]);
                if ($res['city'] && $res['city'] !== $row->city) {
                    $this->audit('vendor_pickup_locations', (int) $row->id, 'city', (string) $row->city);
                    DB::table('vendor_pickup_locations')->where('id', $row->id)->update(['city' => $res['city']]);
                }
            }
        }

        if (Schema::hasTable('shops') && Schema::hasColumn('shops', 'address')) {
            foreach (DB::table('shops')->whereNotNull('address')->get(['id', 'address']) as $row) {
                $addr = is_string($row->address) ? json_decode($row->address, true) : $row->address;
                if (!is_array($addr)) {
                    continue;
                }
                $fixed = $n->normalizeAddressJson($addr);
                if ($fixed !== $addr) {
                    $this->audit('shops', (int) $row->id, 'address', is_string($row->address) ? $row->address : json_encode($row->address));
                    DB::table('shops')->where('id', $row->id)->update(['address' => json_encode($fixed)]);
                }
            }
        }
    }

    private function audit(string $table, int $id, string $column, ?string $old): void
    {
        if (!Schema::hasTable(self::AUDIT)) {
            return;
        }
        DB::table(self::AUDIT)->insert([
            'table_name' => $table, 'row_id' => $id, 'column_name' => $column,
            'old_value' => $old, 'created_at' => now(),
        ]);
    }
};
