<?php

namespace Tests\Feature\Serviceability;

use App\Modules\Serviceability\Application\DeliveryCoverageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Repositories\CheckoutRepository;

/**
 * The Delivery Coverage checkout hard gate (CheckoutRepository::applyCoverageGate,
 * wired into verify() between the city-scope gate and repricing). The full
 * verify() flow needs users/wallets/pricing tables, so the gate was extracted
 * into a protected method and is exercised directly here via a thin subclass —
 * the verify() wiring itself is additive (merge into unavailable_products +
 * optional `coverage` key) and is asserted by code review, not HTTP.
 *
 * Guard chain (each miss = silent fail-open): settings flag
 * `coverageCheckoutGate`, >= 6-digit zip, bridge resolvable, any rule configured.
 */
class CheckoutCoverageGateTest extends ServiceabilityTestCase
{
    use SeedsCoverageGeo;

    private DeliveryCoverageService $coverage;

    /** @var CheckoutRepository gate exposed */
    private $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCoverageGeo();
        $this->coverage = $this->app->make(DeliveryCoverageService::class);

        // Marvel legacy replicas the gate reads: settings (flag) + products (line → shop).
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->json('options')->nullable();
            $t->string('language', 8)->default('en');
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
        });
        // Product 11 belongs to shop 1, product 22 to shop 2.
        DB::table('products')->insert([
            ['id' => 11, 'shop_id' => 1, 'name' => 'Areca Palm', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 22, 'shop_id' => 2, 'name' => 'Snake Plant', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->repo = new class extends CheckoutRepository {
            public function gate(array $lines, ?string $zip): array
            {
                return $this->applyCoverageGate($lines, $zip);
            }

            public function zipOf($request): ?string
            {
                return $this->shippingZip($request);
            }
        };
    }

    private function setFlag(?bool $on): void
    {
        DB::table('settings')->delete();
        $options = $on === null ? [] : ['coverageCheckoutGate' => $on];
        DB::table('settings')->insert([
            'options'    => json_encode($options + ['minimumOrderAmount' => 0]),
            'language'   => defined('DEFAULT_LANGUAGE') ? DEFAULT_LANGUAGE : 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function lines(int ...$productIds): array
    {
        return array_map(fn ($id) => ['product_id' => $id, 'order_quantity' => 1, 'subtotal' => 100], $productIds);
    }

    public function test_flag_off_means_no_gate_even_for_an_uncovered_pincode(): void
    {
        $this->setFlag(null); // flag absent (default OFF)
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);

        $result = $this->repo->gate($this->lines(22), '302001'); // shop 2 covers nothing

        $this->assertSame([], $result['blocked']);
        $this->assertNull($result['coverage']);

        $this->setFlag(false); // flag explicitly off
        $result = $this->repo->gate($this->lines(22), '302001');
        $this->assertSame([], $result['blocked']);
    }

    public function test_flag_on_with_no_rules_configured_fails_open(): void
    {
        $this->setFlag(true);

        $result = $this->repo->gate($this->lines(11, 22), '122001');

        $this->assertSame([], $result['blocked']);
        $this->assertNull($result['coverage']);
    }

    public function test_covered_pincode_blocks_nothing_and_uncovered_blocks_the_line(): void
    {
        $this->setFlag(true);
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);
        // Shop 2 opts into coverage with a rule that does NOT reach 122001.
        $this->coverage->addCoverage(2, 'pincode_include', ['pincode' => '302001']);

        // Product 11 (shop 1) ships to 122001 — covered, no blocks.
        $ok = $this->repo->gate($this->lines(11), '122001');
        $this->assertSame([], $ok['blocked']);
        $this->assertNull($ok['coverage']);

        // Product 22 (shop 2, configured but not covering 122001) blocks;
        // product 11 does not.
        $mixed = $this->repo->gate($this->lines(11, 22), '122001');
        $this->assertSame([22], $mixed['blocked']);
        $this->assertSame('122001', $mixed['coverage']['pincode']);
        $this->assertSame([22], $mixed['coverage']['blocked_products']);
        $this->assertSame('pincode_not_covered', $mixed['coverage']['reason']);
        $this->assertSame("Some items can't be delivered to 122001.", $mixed['coverage']['message']);

        // Shop 1 does not cover Jaipur — its own line blocks there.
        $jaipur = $this->repo->gate($this->lines(11), '302001');
        $this->assertSame([11], $jaipur['blocked']);
    }

    public function test_vendor_without_rules_fails_open_per_vendor(): void
    {
        // Enforcement is PER-VENDOR opt-in (single-shop master catalog: the
        // master shop and unmigrated vendors carry no rules and must never
        // block). Coverage is "configured" platform-wide via shop 1, but
        // shop 2 has no rules → its line passes anywhere.
        $this->setFlag(true);
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);

        $result = $this->repo->gate($this->lines(22), '999999');
        $this->assertSame([], $result['blocked']);
        $this->assertNull($result['coverage']);
    }

    public function test_rule_change_between_carts_starts_blocking(): void
    {
        $this->setFlag(true);
        $rule = $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);
        // A second shop-1 rule keeps the vendor coverage-configured after the
        // district rule is removed (removing the LAST rule = vendor opts out
        // of enforcement entirely, by design).
        $this->coverage->addCoverage(1, 'pincode_include', ['pincode' => '302001']);

        $this->assertSame([], $this->repo->gate($this->lines(11), '122001')['blocked']);

        $this->coverage->removeCoverage(1, $rule->id);

        $this->assertSame([11], $this->repo->gate($this->lines(11), '122001')['blocked']);
    }

    public function test_missing_or_short_zip_fails_open(): void
    {
        $this->setFlag(true);
        $this->coverage->addCoverage(1, 'district', ['district_id' => $this->geo['gurgaon']]);

        $this->assertSame([], $this->repo->gate($this->lines(22), null)['blocked']);
        $this->assertSame([], $this->repo->gate($this->lines(22), '1220')['blocked']); // < 6 digits
    }

    public function test_inactive_vendor_shops_never_count_as_covering(): void
    {
        $this->setFlag(true);
        // Shop 2 is INACTIVE in the fixture — its rule projects, but
        // getAvailableNurseryIds joins shops.is_active, so it cannot cover.
        $this->coverage->addCoverage(2, 'pincode_include', ['pincode' => '122001']);

        $result = $this->repo->gate($this->lines(22), '122001');

        $this->assertSame([22], $result['blocked']);
    }

    public function test_shipping_zip_extraction_matches_the_verify_payload_shapes(): void
    {
        $this->assertSame('122001', $this->repo->zipOf(['shipping_address' => ['zip' => '122001']]));
        $this->assertSame('122001', $this->repo->zipOf(['shipping_address' => ['address' => ['zip' => '122-001']]]));
        $this->assertSame('122001', $this->repo->zipOf(['shipping_address' => ['pincode' => ' 122001 ']]));
        $this->assertSame('122001', $this->repo->zipOf(['shipping_address' => ['postal_code' => '122001']]));
        $this->assertNull($this->repo->zipOf(['shipping_address' => ['city' => 'Gurugram']]));
        $this->assertNull($this->repo->zipOf([]));
    }
}
