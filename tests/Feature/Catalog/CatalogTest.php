<?php

namespace Tests\Feature\Catalog;

/**
 * Phase 2 acceptance: create category → product → variant; a nursery cannot
 * create a product but can file a proposal; publishing emits ProductPublished
 * to the outbox. All responses use the standard envelope.
 */
class CatalogTest extends CatalogTestCase
{
    private function admin(): array
    {
        return $this->bearer($this->accessToken('admin@plantathome.test'));
    }

    /* ── happy path: category → product → variant ──────────────────────────── */

    public function test_admin_can_create_a_category_product_and_variant(): void
    {
        $cat = $this->postJson('/api/v1/catalog/categories', ['name' => 'Indoor Plants'], $this->admin())
            ->assertStatus(201)
            ->assertJsonPath('data.slug', 'indoor-plants');
        $categoryUuid = $cat->json('data.uuid');

        $prod = $this->postJson('/api/v1/catalog/products', [
            'name'          => 'Monstera Deliciosa',
            'category_uuid' => $categoryUuid,
            'botanical_name' => 'Monstera deliciosa',
            'status'        => 'published',
            'variants'      => [['size_code' => 'M', 'name' => 'Medium']],
        ], $this->admin());
        $prod->assertStatus(201)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.category.uuid', $categoryUuid);
        $productUuid = $prod->json('data.uuid');
        $this->assertCount(1, $prod->json('data.variants'));

        // add a second variant
        $this->postJson("/api/v1/catalog/products/{$productUuid}/variants", ['size_code' => 'L', 'name' => 'Large'], $this->admin())
            ->assertStatus(201)
            ->assertJsonPath('data.size_code', 'L');

        $this->getJson("/api/v1/catalog/products/{$productUuid}")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.variants');
    }

    public function test_publishing_a_product_emits_product_published_to_the_outbox(): void
    {
        // A draft product emits nothing.
        $draft = $this->postJson('/api/v1/catalog/products', ['name' => 'Snake Plant', 'status' => 'draft'], $this->admin())
            ->assertStatus(201);
        $this->assertDatabaseMissing('outbox', ['event_name' => 'catalog.product_published']);

        // Publishing it emits the event to the outbox.
        $uuid = $draft->json('data.uuid');
        $this->postJson("/api/v1/catalog/products/{$uuid}/publish", [], $this->admin())
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'published');

        $this->assertDatabaseHas('outbox', [
            'event_name' => 'catalog.product_published',
            'status'     => 'pending',
        ]);
    }

    public function test_creating_a_published_product_emits_the_event_immediately(): void
    {
        $this->postJson('/api/v1/catalog/products', ['name' => 'Pothos', 'status' => 'published'], $this->admin())
            ->assertStatus(201);

        $this->assertDatabaseHas('outbox', ['event_name' => 'catalog.product_published']);
    }

    /* ── ownership contract: nurseries never create products ───────────────── */

    public function test_a_nursery_cannot_create_a_product(): void
    {
        $token = $this->bearer($this->accessToken('owner.a@plantathome.test'));

        $this->postJson('/api/v1/catalog/products', ['name' => 'Sneaky Plant'], $token)
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'FORBIDDEN');
    }

    public function test_a_nursery_can_file_a_product_proposal(): void
    {
        $token = $this->bearer($this->accessToken('owner.a@plantathome.test'));

        $res = $this->postJson('/api/v1/catalog/proposals', [
            'name'           => 'Rare Philodendron',
            'botanical_name' => 'Philodendron spiritus-sancti',
        ], $token);

        $res->assertStatus(201)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.nursery_id', \App\Modules\Identity\Database\Seeders\IdentityAccessSeeder::NURSERY_A);

        $this->assertDatabaseHas('outbox', ['event_name' => 'catalog.product_proposal_filed']);
    }

    public function test_admin_can_approve_a_proposal_materializing_a_draft_product(): void
    {
        $nursery = $this->bearer($this->accessToken('owner.a@plantathome.test'));
        $proposalUuid = $this->postJson('/api/v1/catalog/proposals', ['name' => 'Calathea Orbifolia'], $nursery)
            ->assertStatus(201)->json('data.uuid');

        $approved = $this->postJson("/api/v1/catalog/proposals/{$proposalUuid}/approve", ['note' => 'looks good'], $this->admin());
        $approved->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');
        $this->assertNotNull($approved->json('data.product_uuid'));

        // The materialized product exists as a DRAFT (visible to an admin).
        $productUuid = $approved->json('data.product_uuid');
        $this->getJson("/api/v1/catalog/products/{$productUuid}", $this->admin())
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.name', 'Calathea Orbifolia');
    }

    public function test_a_nursery_cannot_approve_a_proposal(): void
    {
        $nursery = $this->bearer($this->accessToken('owner.a@plantathome.test'));
        $proposalUuid = $this->postJson('/api/v1/catalog/proposals', ['name' => 'Fiddle Leaf Fig'], $nursery)
            ->assertStatus(201)->json('data.uuid');

        $this->postJson("/api/v1/catalog/proposals/{$proposalUuid}/approve", [], $nursery)
            ->assertStatus(403);
    }

    /* ── authorization boundaries ──────────────────────────────────────────── */

    public function test_a_customer_cannot_write_the_catalog(): void
    {
        $token = $this->bearer($this->accessToken('customer@plantathome.test'));

        $this->postJson('/api/v1/catalog/products', ['name' => 'X'], $token)->assertStatus(403);
        $this->postJson('/api/v1/catalog/proposals', ['name' => 'X'], $token)->assertStatus(403);
    }

    public function test_catalog_writes_require_authentication(): void
    {
        $this->postJson('/api/v1/catalog/products', ['name' => 'X'])->assertStatus(401);
    }

    public function test_public_can_list_published_products_only(): void
    {
        $this->postJson('/api/v1/catalog/products', ['name' => 'Public Published', 'status' => 'published'], $this->admin())->assertStatus(201);
        $this->postJson('/api/v1/catalog/products', ['name' => 'Secret Draft', 'status' => 'draft'], $this->admin())->assertStatus(201);

        $res = $this->getJson('/api/v1/catalog/products')->assertStatus(200);
        $names = array_column($res->json('data'), 'name');
        $this->assertContains('Public Published', $names);
        $this->assertNotContains('Secret Draft', $names);
    }

    public function test_a_guest_cannot_bypass_the_published_gate_with_a_status_filter(): void
    {
        $this->postJson('/api/v1/catalog/products', ['name' => 'Sneaky Draft', 'status' => 'draft'], $this->admin())->assertStatus(201);

        // A client-supplied ?status=draft (or =all) must NOT leak drafts to a guest.
        foreach (['draft', 'all', 'archived'] as $status) {
            $names = array_column($this->getJson("/api/v1/catalog/products?status={$status}")->assertStatus(200)->json('data'), 'name');
            $this->assertNotContains('Sneaky Draft', $names, "status={$status} leaked a draft");
        }
    }

    public function test_a_guest_gets_404_for_a_draft_product_show(): void
    {
        $uuid = $this->postJson('/api/v1/catalog/products', ['name' => 'Hidden', 'status' => 'draft'], $this->admin())
            ->assertStatus(201)->json('data.uuid');

        // Guest: not found. Admin: visible.
        $this->getJson("/api/v1/catalog/products/{$uuid}")->assertStatus(404)->assertJsonPath('errors.0.code', 'NOT_FOUND');
        $this->getJson("/api/v1/catalog/products/{$uuid}", $this->admin())->assertStatus(200)->assertJsonPath('data.status', 'draft');
    }

    public function test_creating_a_product_with_an_unknown_category_is_422_not_500(): void
    {
        $this->postJson('/api/v1/catalog/products', [
            'name'          => 'Orphan',
            'category_uuid' => '99999999-9999-9999-9999-999999999999',
        ], $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'VALIDATION_ERROR');
    }

    public function test_approving_a_non_pending_proposal_is_409_not_500(): void
    {
        $nursery = $this->bearer($this->accessToken('owner.a@plantathome.test'));
        $uuid = $this->postJson('/api/v1/catalog/proposals', ['name' => 'Once'], $nursery)->assertStatus(201)->json('data.uuid');

        $this->postJson("/api/v1/catalog/proposals/{$uuid}/approve", [], $this->admin())->assertStatus(200);
        // Second approval of the now-approved proposal is a clean conflict.
        $this->postJson("/api/v1/catalog/proposals/{$uuid}/approve", [], $this->admin())
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'PROPOSAL_NOT_PENDING');
    }

    public function test_category_supports_partial_update_and_rejects_reparenting(): void
    {
        $uuid = $this->postJson('/api/v1/catalog/categories', ['name' => 'Original'], $this->admin())
            ->assertStatus(201)->json('data.uuid');

        // Partial update (name omitted-elsewhere fields) succeeds.
        $this->patchJson("/api/v1/catalog/categories/{$uuid}", ['sort' => 5], $this->admin())
            ->assertStatus(200)
            ->assertJsonPath('data.sort', 5)
            ->assertJsonPath('data.name', 'Original');

        // Re-parenting is explicitly rejected (not silently ignored).
        $this->patchJson("/api/v1/catalog/categories/{$uuid}", ['parent_uuid' => '11111111-1111-1111-1111-111111111111'], $this->admin())
            ->assertStatus(422);
    }
}
