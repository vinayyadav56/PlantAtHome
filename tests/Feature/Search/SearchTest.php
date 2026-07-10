<?php

namespace Tests\Feature\Search;

use App\Modules\Identity\Database\Seeders\IdentityAccessSeeder;
use App\Shared\Events\OutboxRelay;

/**
 * Phase 9 acceptance: the search projection is kept in sync by domain events
 * (via the outbox), search + autocomplete + facets work, drafts are excluded,
 * city-aware filtering composes Serviceability, and the backend degrades to
 * MySQL when Elasticsearch is unavailable.
 */
class SearchTest extends SearchTestCase
{
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ids = $this->seedProducts();
    }

    private function admin(): array
    {
        return $this->bearer($this->accessToken('admin@plantathome.test'));
    }

    /** Publish two products via the catalog, then drain the outbox to index them. */
    private function seedProducts(): array
    {
        $admin = $this->admin();
        $cat = $this->postJson('/api/v1/catalog/categories', ['name' => 'Indoor'], $admin)->json('data.uuid');
        $monstera = $this->postJson('/api/v1/catalog/products', ['name' => 'Monstera Deliciosa', 'category_uuid' => $cat, 'status' => 'published'], $admin);
        $snake = $this->postJson('/api/v1/catalog/products', ['name' => 'Snake Plant', 'category_uuid' => $cat, 'status' => 'published'], $admin);

        // Add a variant + stock + coverage so city availability can resolve.
        $variant = $this->postJson("/api/v1/catalog/products/{$monstera->json('data.uuid')}/variants", ['size_code' => 'M'], $admin)->json('data.uuid');

        $this->drainOutbox(); // event-driven indexing

        return [
            'category'  => $cat,
            'monstera'  => $monstera->json('data.uuid'),
            'snake'     => $snake->json('data.uuid'),
            'variant'   => $variant,
        ];
    }

    private function drainOutbox(): void
    {
        $this->app->make(OutboxRelay::class)->relay();
    }

    /* ── acceptance ────────────────────────────────────────────────────────── */

    public function test_products_are_indexed_by_events_and_searchable(): void
    {
        $res = $this->getJson('/api/v1/search?q=Monstera')->assertStatus(200);

        $names = array_column($res->json('data'), 'name');
        $this->assertContains('Monstera Deliciosa', $names);
        $this->assertNotContains('Snake Plant', $names);
        $this->assertSame('mysql', $res->json('meta.backend')); // ES not configured → fallback
    }

    public function test_search_without_a_query_returns_all_with_category_facets(): void
    {
        $res = $this->getJson('/api/v1/search')->assertStatus(200);

        $this->assertSame(2, $res->json('meta.total'));
        $facet = collect($res->json('meta.facets.category'))->firstWhere('uuid', $this->ids['category']);
        $this->assertSame(2, $facet['count']);
    }

    public function test_category_filter_narrows_results(): void
    {
        $res = $this->getJson("/api/v1/search?filter[category]={$this->ids['category']}")->assertStatus(200);
        $this->assertSame(2, $res->json('meta.total'));

        $this->getJson('/api/v1/search?filter[category]=99999999-9999-9999-9999-999999999999')
            ->assertStatus(200)->assertJsonPath('meta.total', 0);
    }

    public function test_autocomplete_suggests_by_prefix(): void
    {
        $this->getJson('/api/v1/search/autocomplete?q=Mon')
            ->assertStatus(200)
            ->assertJsonPath('data.0', 'Monstera Deliciosa');
    }

    public function test_a_draft_product_is_not_indexed(): void
    {
        $draft = $this->postJson('/api/v1/catalog/products', ['name' => 'Secret Fern', 'status' => 'draft'], $this->admin());
        $this->drainOutbox();

        $names = array_column($this->getJson('/api/v1/search?q=Fern')->json('data'), 'name');
        $this->assertNotContains('Secret Fern', $names);
    }

    public function test_city_filter_composes_serviceability(): void
    {
        // Monstera is stocked + covered in a city; Snake Plant is not.
        $admin = $this->admin();
        $city = $this->postJson('/api/v1/serviceability/cities', ['name' => 'Pune'], $admin)->json('data.uuid');
        $this->putJson('/api/v1/inventory/stock', ['sellable_type' => 'variant', 'sellable_uuid' => $this->ids['variant'], 'nursery_id' => IdentityAccessSeeder::NURSERY_A, 'qty_on_hand' => 3], $admin)->assertStatus(200);
        $this->putJson('/api/v1/serviceability/coverage', ['nursery_id' => IdentityAccessSeeder::NURSERY_A, 'city_uuid' => $city, 'delivery_radius_km' => 0], $admin)->assertStatus(200);

        $res = $this->getJson("/api/v1/search?city={$city}")->assertStatus(200);
        $names = array_column($res->json('data'), 'name');

        $this->assertTrue($res->json('meta.city_filtered'));
        $this->assertContains('Monstera Deliciosa', $names);   // available in city
        $this->assertNotContains('Snake Plant', $names);       // not available there
    }

    public function test_a_stale_projection_row_does_not_abort_city_search(): void
    {
        $admin = $this->admin();
        // A city with a serving, stocked vendor for the real (Monstera) product.
        $city = $this->postJson('/api/v1/serviceability/cities', ['name' => 'Nagpur'], $admin)->json('data.uuid');
        $this->putJson('/api/v1/inventory/stock', ['sellable_type' => 'variant', 'sellable_uuid' => $this->ids['variant'], 'nursery_id' => IdentityAccessSeeder::NURSERY_A, 'qty_on_hand' => 3], $admin)->assertStatus(200);
        $this->putJson('/api/v1/serviceability/coverage', ['nursery_id' => IdentityAccessSeeder::NURSERY_A, 'city_uuid' => $city, 'delivery_radius_km' => 0], $admin)->assertStatus(200);

        // A STALE projection row whose product no longer exists in the catalog:
        // productAvailability() throws PRODUCT_NOT_FOUND for it. City search must
        // filter it out, not 500 on the whole query.
        \App\Modules\Search\Infrastructure\Models\SearchProduct::create([
            'product_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Monstera Ghost', 'slug' => 'ghost', 'keywords' => 'Monstera Ghost', 'status' => 'published',
        ]);

        $res = $this->getJson("/api/v1/search?q=Monstera&city={$city}")->assertStatus(200);
        $names = array_column($res->json('data'), 'name');
        $this->assertContains('Monstera Deliciosa', $names);
        $this->assertNotContains('Monstera Ghost', $names);
    }
}
