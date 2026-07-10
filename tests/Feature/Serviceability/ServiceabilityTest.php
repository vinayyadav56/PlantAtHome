<?php

namespace Tests\Feature\Serviceability;

use App\Modules\Identity\Database\Seeders\IdentityAccessSeeder;

/**
 * Phase 7 acceptance: a product hides in an unserviced city (no serving vendor,
 * or serving vendor out of stock); local-delivery eligibility resolves by radius.
 */
class ServiceabilityTest extends ServiceabilityTestCase
{
    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->seedScenario();
    }

    private function admin(): array
    {
        return $this->bearer($this->accessToken('admin@plantathome.test'));
    }

    private function seedScenario(): array
    {
        $admin = $this->admin();
        $city = $this->postJson('/api/v1/serviceability/cities', ['name' => 'Gurugram'], $admin)->assertStatus(201)->json('data.uuid');
        $product = $this->postJson('/api/v1/catalog/products', [
            'name' => 'Areca Palm', 'status' => 'published', 'variants' => [['size_code' => 'M']],
        ], $admin);
        $productUuid = $product->json('data.uuid');
        $variantUuid = $product->json('data.variants.0.uuid');

        return compact('city', 'productUuid', 'variantUuid');
    }

    private function availability(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson("/api/v1/serviceability/availability?city={$this->ctx['city']}&product={$this->ctx['productUuid']}");
    }

    private function setCoverage(float $radius = 0, ?float $lat = null, ?float $lng = null): void
    {
        $this->putJson('/api/v1/serviceability/coverage', array_filter([
            'nursery_id' => IdentityAccessSeeder::NURSERY_A, 'city_uuid' => $this->ctx['city'],
            'delivery_radius_km' => $radius, 'center_lat' => $lat, 'center_lng' => $lng,
        ], fn ($v) => $v !== null), $this->admin())->assertStatus(200);
    }

    private function stock(int $qty): void
    {
        $this->putJson('/api/v1/inventory/stock', [
            'sellable_type' => 'variant', 'sellable_uuid' => $this->ctx['variantUuid'],
            'nursery_id' => IdentityAccessSeeder::NURSERY_A, 'qty_on_hand' => $qty,
        ], $this->admin())->assertStatus(200);
    }

    /* ── acceptance ────────────────────────────────────────────────────────── */

    public function test_a_product_hides_in_a_city_with_no_serving_vendor(): void
    {
        $this->stock(5); // stocked, but nobody serves the city
        $this->availability()
            ->assertStatus(200)
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'no_vendor_serves_city');
    }

    public function test_a_product_is_available_when_a_serving_vendor_has_stock(): void
    {
        $this->setCoverage();
        $this->stock(5);

        $res = $this->availability()->assertStatus(200)->assertJsonPath('data.available', true);
        $this->assertSame(1, $res->json('data.in_stock_vendor_count'));
    }

    public function test_public_availability_does_not_leak_supplier_uuids(): void
    {
        $this->setCoverage();
        $this->stock(5);

        $data = $this->availability()->assertStatus(200)->json('data');
        // Public endpoint exposes booleans + counts only — never raw nursery_id UUIDs.
        $this->assertArrayNotHasKey('serving_vendors', $data);
        $this->assertArrayNotHasKey('in_stock_vendors', $data);
        $this->assertSame(1, $data['serving_vendor_count']);
        $this->assertSame(1, $data['in_stock_vendor_count']);
    }

    public function test_a_product_hides_when_the_serving_vendor_is_out_of_stock(): void
    {
        $this->setCoverage();
        $this->stock(0);

        $this->availability()
            ->assertStatus(200)
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'out_of_stock_in_city');
    }

    public function test_city_serviceable_reflects_coverage(): void
    {
        $this->getJson("/api/v1/serviceability/cities/{$this->ctx['city']}/serviceable")
            ->assertStatus(200)->assertJsonPath('data.serviceable', false);

        $this->setCoverage();

        $this->getJson("/api/v1/serviceability/cities/{$this->ctx['city']}/serviceable")
            ->assertStatus(200)->assertJsonPath('data.serviceable', true);
    }

    public function test_public_serviceable_does_not_leak_supplier_uuids(): void
    {
        $this->setCoverage();
        $data = $this->getJson("/api/v1/serviceability/cities/{$this->ctx['city']}/serviceable")
            ->assertStatus(200)->json('data');

        $this->assertTrue($data['serviceable']);
        $this->assertArrayNotHasKey('serving_vendors', $data); // no raw nursery_id UUIDs
        $this->assertSame(1, $data['serving_vendor_count']);
    }

    /* ── radius eligibility ────────────────────────────────────────────────── */

    public function test_local_delivery_eligibility_resolves_by_radius(): void
    {
        // Vendor centred at Gurugram (28.4595, 77.0266) with a 5 km radius.
        $this->setCoverage(5.0, 28.4595, 77.0266);

        $check = fn ($lat, $lng) => $this->postJson('/api/v1/serviceability/delivery-check', [
            'nursery_id' => IdentityAccessSeeder::NURSERY_A, 'city_uuid' => $this->ctx['city'], 'lat' => $lat, 'lng' => $lng,
        ])->json('data.eligible');

        // ~2 km away → eligible; ~50 km away (Delhi) → not.
        $this->assertTrue($check(28.47, 77.04));
        $this->assertFalse($check(28.7041, 77.1025));
    }

    /* ── authorization ─────────────────────────────────────────────────────── */

    public function test_creating_a_city_requires_permission(): void
    {
        $customer = $this->bearer($this->accessToken('customer@plantathome.test'));
        $this->postJson('/api/v1/serviceability/cities', ['name' => 'X'], $customer)->assertStatus(403);
    }

    public function test_a_nursery_cannot_set_another_nurserys_coverage(): void
    {
        $ownerA = $this->bearer($this->accessToken('owner.a@plantathome.test'));
        $this->putJson('/api/v1/serviceability/coverage', [
            'nursery_id' => IdentityAccessSeeder::NURSERY_B, 'city_uuid' => $this->ctx['city'], 'delivery_radius_km' => 5,
        ], $ownerA)->assertStatus(403);
    }
}
