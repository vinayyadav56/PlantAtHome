<?php

namespace Tests\Feature\Rules;

/**
 * Phase 4 acceptance: rules are wired into Configuration resolution (a VISIBILITY
 * rule hides an option) and adding/removing a rule ROW changes behaviour with
 * zero code change; admin-only rule CRUD.
 */
class RuleIntegrationTest extends RulesTestCase
{
    private function admin(): array
    {
        return $this->bearer($this->accessToken('admin@plantathome.test'));
    }

    private function giftOptions(string $product, string $variant): array
    {
        $data = $this->getJson("/api/v1/config/products/{$product}/configuration?variant={$variant}")->json('data');
        foreach ($data['groups'] as $g) {
            if ($g['code'] === 'gift') {
                return array_column($g['options'], 'name');
            }
        }

        return [];
    }

    public function test_non_admin_cannot_manage_rules(): void
    {
        $customer = $this->bearer($this->accessToken('customer@plantathome.test'));
        $this->postJson('/api/v1/rules', ['name' => 'x', 'scope' => 'visibility', 'actions' => [['type' => 'hide_option']]], $customer)
            ->assertStatus(403);
    }

    public function test_a_visibility_rule_hides_an_option_and_removing_it_restores(): void
    {
        $admin = $this->admin();

        // Product with S/L variants, a 'gift' group + 'Gift Wrap' option.
        $product = $this->postJson('/api/v1/catalog/products', [
            'name' => 'Fern', 'status' => 'published',
            'variants' => [['size_code' => 'S'], ['size_code' => 'L']],
        ], $admin);
        $productUuid = $product->json('data.uuid');
        $variants = collect($product->json('data.variants'))->keyBy('size_code');
        $gift = $this->postJson('/api/v1/config/groups', ['code' => 'gift', 'name' => 'Gift'], $admin)->json('data.uuid');
        $this->postJson("/api/v1/config/groups/{$gift}/options", ['name' => 'Gift Wrap'], $admin)->assertStatus(201);
        $this->postJson("/api/v1/config/products/{$productUuid}/assignments", ['group_uuid' => $gift], $admin)->assertStatus(201);

        // Baseline: Gift Wrap visible for the S variant.
        $this->assertContains('Gift Wrap', $this->giftOptions($productUuid, $variants['S']['uuid']));

        // Add a rule (DATA) that hides Gift Wrap for size S.
        $rule = $this->postJson('/api/v1/rules', [
            'name' => 'Hide gift wrap on small', 'scope' => 'visibility',
            'conditions' => [['fact' => 'variant.size', 'operator' => 'eq', 'value' => 'S']],
            'actions'    => [['type' => 'hide_option', 'params' => ['group' => 'gift', 'option' => 'Gift Wrap']]],
        ], $admin)->assertStatus(201)->json('data.uuid');

        // Now hidden for S, still visible for L — zero code change.
        $this->assertNotContains('Gift Wrap', $this->giftOptions($productUuid, $variants['S']['uuid']));
        $this->assertContains('Gift Wrap', $this->giftOptions($productUuid, $variants['L']['uuid']));

        // Remove the rule ROW → behaviour reverts.
        $this->deleteJson("/api/v1/rules/{$rule}", [], $admin)->assertStatus(200);
        $this->assertContains('Gift Wrap', $this->giftOptions($productUuid, $variants['S']['uuid']));
    }

    public function test_a_compatibility_rule_blocks_a_selection(): void
    {
        $admin = $this->admin();

        $product = $this->postJson('/api/v1/catalog/products', [
            'name' => 'Cactus', 'status' => 'published', 'variants' => [['size_code' => 'S']],
        ], $admin);
        $productUuid = $product->json('data.uuid');
        $variant = $product->json('data.variants.0.uuid');
        $acc = $this->postJson('/api/v1/config/groups', ['code' => 'accessories', 'name' => 'Accessories', 'select_type' => 'multi'], $admin)->json('data.uuid');
        $pebbles = $this->postJson("/api/v1/config/groups/{$acc}/options", ['name' => 'Pebbles'], $admin)->json('data.uuid');
        $this->postJson("/api/v1/config/products/{$productUuid}/assignments", ['group_uuid' => $acc], $admin)->assertStatus(201);

        // Rule: if the selection contains this pebbles option uuid → block.
        $this->postJson('/api/v1/rules', [
            'name' => 'Pebbles blocked demo', 'scope' => 'compatibility',
            'conditions' => [['fact' => 'selection.options', 'operator' => 'contains', 'value' => $pebbles]],
            'actions'    => [['type' => 'block_selection', 'params' => ['reason' => 'Pebbles need a pot.']]],
        ], $admin)->assertStatus(201);

        $res = $this->postJson("/api/v1/config/products/{$productUuid}/validate-selection", [
            'variant' => $variant, 'selection' => ['accessories' => [$pebbles]],
        ])->assertStatus(200);

        $this->assertFalse($res->json('data.valid'));
        $this->assertContains('RULE_BLOCKED', array_column($res->json('data.violations'), 'code'));
    }
}
