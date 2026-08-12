<?php

declare(strict_types=1);

namespace Tests\Feature\Courier;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Services\AvailabilityService;
use Marvel\Services\ItemAssignmentService;
use Marvel\Services\VendorSelection\NearestVendorStrategy;
use Tests\TestCase;

/**
 * What the admin's per-line vendor picker shows: how far each vendor ships from,
 * and what the platform keeps by choosing it.
 *
 * The customer price is UNIFORM across candidates by design (max vendor rate +
 * margin), so the vendor choice moves only the margin — that is the whole trade-off
 * the operator is making, and it has to be visible and correct.
 */
final class CandidateMarginDistanceTest extends TestCase
{
    /** Greater Kailash, New Delhi — the delivery address. */
    private const CUSTOMER = ['lat' => 28.593645, 'lng' => 77.182904];

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
            $t->text('address')->nullable();
            $t->text('settings')->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->decimal('vendor_rating', 3, 2)->nullable();
            $t->integer('vendor_priority_score')->nullable();
            $t->integer('sla_default_days')->nullable();
            $t->timestamps();
        });
        Schema::create('vendor_service_areas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id');
            $t->string('city')->nullable();
            $t->string('pincode')->nullable();
            $t->string('fulfillment_mode')->nullable();
            $t->integer('eta_days')->nullable();
            // loadAreas()/loadRates() both filter on is_active — without it every
            // vendor silently has no service area and no candidate survives.
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('vendor_shipping_rates', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id');
            $t->string('fulfillment_mode')->nullable();
            $t->decimal('rate', 12, 2)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->unsignedBigInteger('type_id')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });

        DB::table('products')->insert(['id' => 1, 'name' => 'Dracaena Cinnabari']);

        // NEAR: correctly pinned in Delhi, ~8.7 km out. Charges MORE.
        DB::table('shops')->insert([
            'id' => 33, 'name' => 'Delhi Nursery 2',
            'address' => json_encode(['city' => 'New Delhi']),
            'lat' => 28.53536, 'lng' => 77.2419522,
        ]);
        // FAR: pinned in Rewari, ~93 km out. Charges LESS — the real trade-off.
        DB::table('shops')->insert([
            'id' => 32, 'name' => 'Delhi Nursery',
            'address' => json_encode(['city' => 'New Delhi']),
            'lat' => 28.1867008, 'lng' => 76.3535690,
        ]);
        foreach ([32, 33] as $shopId) {
            DB::table('vendor_service_areas')->insert([
                'shop_id' => $shopId, 'city' => 'New Delhi', 'fulfillment_mode' => 'local',
                'eta_days' => 2, 'is_active' => true,
            ]);
        }
    }

    /** Engine wired to a fixed two-vendor availability answer. */
    private function engine(): ItemAssignmentService
    {
        return new ItemAssignmentService(new FakeAvailability());
    }

    /** @return array<int,array> candidates keyed by shop_id */
    private function byShop(?array $customer = null): array
    {
        $out = [];
        foreach ($this->engine()->candidatesFor(1, null, 2, 'New Delhi', null, $customer) as $c) {
            $out[(int) $c['shop_id']] = $c;
        }
        return $out;
    }

    public function test_every_candidate_quotes_the_same_customer_price(): void
    {
        $c = $this->byShop();
        $this->assertCount(2, $c);
        $this->assertSame(
            $c[32]['selling_price'],
            $c[33]['selling_price'],
            'the vendor choice must never change what the customer pays',
        );
    }

    public function test_margin_is_the_customer_price_less_what_the_vendor_is_paid(): void
    {
        $c = $this->byShop();

        foreach ([32, 33] as $shopId) {
            $this->assertEqualsWithDelta(
                (float) $c[$shopId]['selling_price'] - (float) $c[$shopId]['vendor_rate'],
                (float) $c[$shopId]['margin_per_unit'],
                0.01,
                "margin for shop {$shopId}",
            );
        }
        // qty 2 — the picker shows the line total, not just the unit.
        $this->assertEqualsWithDelta(
            (float) $c[32]['margin_per_unit'] * 2,
            (float) $c[32]['margin_total'],
            0.01,
        );
        // The cheaper vendor yields the bigger margin, since the price is uniform.
        $this->assertGreaterThan((float) $c[33]['margin_per_unit'], (float) $c[32]['margin_per_unit']);
    }

    public function test_best_margin_flags_the_cheapest_vendor_and_is_separate_from_recommended(): void
    {
        $c = $this->byShop();

        $this->assertTrue($c[32]['best_margin'], 'shop 32 charges less, so it keeps the most margin');
        $this->assertFalse($c[33]['best_margin']);
        // Exactly one winner, and the flag is independent of the recommendation —
        // collapsing the two would hide the distance/margin trade-off.
        $this->assertSame(1, count(array_filter($c, fn ($x) => $x['best_margin'])));
        $this->assertSame(1, count(array_filter($c, fn ($x) => $x['recommended'])));
    }

    public function test_distance_is_measured_from_each_vendor_to_the_delivery_address(): void
    {
        $c = $this->byShop(self::CUSTOMER);

        // Delhi → Greater Kailash is a short hop; Rewari → Greater Kailash is not.
        $this->assertEqualsWithDelta(8.7, (float) $c[33]['distance_km'], 1.5);
        $this->assertEqualsWithDelta(92.8, (float) $c[32]['distance_km'], 3.0);
        $this->assertGreaterThan((float) $c[33]['distance_km'], (float) $c[32]['distance_km']);
    }

    public function test_distance_is_null_when_the_order_has_no_pin(): void
    {
        // No customer point: the picker shows "—", never a fabricated 0 km, which
        // would read as "this vendor is at the customer's door".
        $c = $this->byShop();

        $this->assertNull($c[32]['distance_km']);
        $this->assertNull($c[33]['distance_km']);
    }

    public function test_nearest_strategy_picks_the_closer_vendor_over_the_cheaper_one(): void
    {
        // 'nearest' claimed to prefer the physically closest vendor but compared
        // pincode coverage then SLA, because no candidate ever carried a distance.
        // With both vendors equal on those, only a real distance can decide.
        $ranked = (new NearestVendorStrategy())->rank(array_values($this->byShop(self::CUSTOMER)), []);

        $this->assertSame(33, (int) $ranked[0]['shop_id'], 'the 8.7 km vendor must beat the 93 km one');
    }
}

/** Two vendors for one product: 33 is dearer, 32 is cheaper. */
class FakeAvailability extends AvailabilityService
{
    public function vendorsForProduct(int $productId, ?int $variationOptionId = null): array
    {
        return [
            [
                'shop_id' => 33, 'vendor_name' => 'Delhi Nursery 2',
                'vendor_rate' => 3209.0, 'selling_price' => 3209.0,
                'is_available' => true, 'track_stock' => false,
                'stock_qty' => 0, 'available_qty' => 0, 'cities' => ['New Delhi'],
            ],
            [
                'shop_id' => 32, 'vendor_name' => 'Delhi Nursery',
                'vendor_rate' => 3109.0, 'selling_price' => 3109.0,
                'is_available' => true, 'track_stock' => false,
                'stock_qty' => 0, 'available_qty' => 0, 'cities' => ['New Delhi'],
            ],
        ];
    }
}
