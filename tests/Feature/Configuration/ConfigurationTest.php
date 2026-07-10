<?php

namespace Tests\Feature\Configuration;

use App\Modules\Identity\Database\Seeders\IdentityAccessSeeder;

/**
 * Phase 3 acceptance: a plant resolves its assigned groups; a large variant only
 * offers large pots (compatibility); a disabled-vendor option drops out
 * (enablement); validateSelection returns ALL violations at once.
 */
class ConfigurationTest extends ConfigurationTestCase
{
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ids = $this->seedScenario();
    }

    private function admin(): array
    {
        return $this->bearer($this->accessToken('admin@plantathome.test'));
    }

    /**
     * Build a product with S/L variants and Pot (size-restricted) / Packaging /
     * Accessories groups — all via the real HTTP admin endpoints.
     */
    private function seedScenario(): array
    {
        $admin = $this->admin();

        $cat = $this->postJson('/api/v1/catalog/categories', ['name' => 'Plants'], $admin)->json('data.uuid');
        $product = $this->postJson('/api/v1/catalog/products', [
            'name' => 'Ficus', 'category_uuid' => $cat, 'status' => 'published',
            'variants' => [['size_code' => 'S', 'name' => 'Small'], ['size_code' => 'L', 'name' => 'Large']],
        ], $admin);
        $productUuid = $product->json('data.uuid');
        $variants = collect($product->json('data.variants'))->keyBy('size_code');

        // Groups + options
        $pot = $this->group('pot', 'Pot', 'single', true, $admin);
        $smallPot = $this->option($pot, 'Small Pot', $admin);
        $largePot = $this->option($pot, 'Large Pot', $admin);

        $packaging = $this->group('packaging', 'Packaging', 'single', false, $admin);
        $giftWrap = $this->option($packaging, 'Gift Wrap', $admin);
        $kraftBox = $this->option($packaging, 'Kraft Box', $admin);

        $accessories = $this->group('accessories', 'Accessories', 'multi', false, $admin);
        $pebbles = $this->option($accessories, 'Pebbles', $admin);
        $moss = $this->option($accessories, 'Moss', $admin);

        // Assign all three groups to the product
        foreach ([$pot, $packaging, $accessories] as $g) {
            $this->postJson("/api/v1/config/products/{$productUuid}/assignments", ['group_uuid' => $g], $admin)->assertStatus(201);
        }

        // Compatibility: Small Pot → size S, Large Pot → size L (others universal)
        $this->postJson("/api/v1/config/options/{$smallPot}/compatibility", ['rules' => [['size_code' => 'S']]], $admin)->assertStatus(200);
        $this->postJson("/api/v1/config/options/{$largePot}/compatibility", ['rules' => [['size_code' => 'L']]], $admin)->assertStatus(200);

        return compact('productUuid') + [
            'variantS' => $variants['S']['uuid'],
            'variantL' => $variants['L']['uuid'],
            'pot' => $pot, 'smallPot' => $smallPot, 'largePot' => $largePot,
            'packaging' => $packaging, 'giftWrap' => $giftWrap, 'kraftBox' => $kraftBox,
            'accessories' => $accessories, 'pebbles' => $pebbles, 'moss' => $moss,
        ];
    }

    private function group(string $code, string $name, string $select, bool $required, array $token): string
    {
        return $this->postJson('/api/v1/config/groups', [
            'code' => $code, 'name' => $name, 'select_type' => $select, 'is_required' => $required,
        ], $token)->assertStatus(201)->json('data.uuid');
    }

    private function option(string $groupUuid, string $name, array $token): string
    {
        return $this->postJson("/api/v1/config/groups/{$groupUuid}/options", ['name' => $name, 'base_price' => 100], $token)
            ->assertStatus(201)->json('data.uuid');
    }

    private function groupCodes(array $data): array
    {
        return array_column($data['groups'], 'code');
    }

    private function optionNames(array $data, string $groupCode): array
    {
        foreach ($data['groups'] as $g) {
            if ($g['code'] === $groupCode) {
                return array_column($g['options'], 'name');
            }
        }

        return [];
    }

    /* ── acceptance ────────────────────────────────────────────────────────── */

    public function test_resolve_returns_the_assigned_groups(): void
    {
        $res = $this->getJson("/api/v1/config/products/{$this->ids['productUuid']}/configuration?variant={$this->ids['variantS']}")
            ->assertStatus(200);

        $this->assertEqualsCanonicalizing(['pot', 'packaging', 'accessories'], $this->groupCodes($res->json('data')));
    }

    public function test_a_large_variant_only_offers_the_large_pot(): void
    {
        $small = $this->getJson("/api/v1/config/products/{$this->ids['productUuid']}/configuration?variant={$this->ids['variantS']}")->json('data');
        $large = $this->getJson("/api/v1/config/products/{$this->ids['productUuid']}/configuration?variant={$this->ids['variantL']}")->json('data');

        $this->assertSame(['Small Pot'], $this->optionNames($small, 'pot'));
        $this->assertSame(['Large Pot'], $this->optionNames($large, 'pot'));
    }

    public function test_a_variant_specific_exclusion_wins_over_a_size_level_match(): void
    {
        $admin = $this->admin();

        // Large Pot fits size L in general, BUT is explicitly excluded for this
        // exact L variant. The variant-specific false must win, regardless of
        // row order.
        $this->postJson("/api/v1/config/options/{$this->ids['largePot']}/compatibility", [
            'rules' => [
                ['size_code' => 'L', 'is_compatible' => true],
                ['variant_uuid' => $this->ids['variantL'], 'is_compatible' => false],
            ],
        ], $admin)->assertStatus(200);

        $large = $this->getJson("/api/v1/config/products/{$this->ids['productUuid']}/configuration?variant={$this->ids['variantL']}")->json('data');

        // Large Pot is excluded for this variant despite the size-L true rule.
        $this->assertNotContains('Large Pot', $this->optionNames($large, 'pot'));
    }

    public function test_a_disabled_vendor_option_drops_out(): void
    {
        $nursery = IdentityAccessSeeder::NURSERY_A;

        // Baseline: nursery A sees Gift Wrap + Kraft Box.
        $before = $this->getJson("/api/v1/config/products/{$this->ids['productUuid']}/configuration?variant={$this->ids['variantS']}&nursery={$nursery}")->json('data');
        $this->assertContains('Gift Wrap', $this->optionNames($before, 'packaging'));

        // Nursery A disables Gift Wrap for itself.
        $owner = $this->bearer($this->accessToken('owner.a@plantathome.test'));
        $this->putJson("/api/v1/config/nursery/{$nursery}/options/{$this->ids['giftWrap']}/enablement", ['is_enabled' => false], $owner)
            ->assertStatus(200);

        // Now Gift Wrap is gone for nursery A, but Kraft Box remains.
        $after = $this->getJson("/api/v1/config/products/{$this->ids['productUuid']}/configuration?variant={$this->ids['variantS']}&nursery={$nursery}")->json('data');
        $this->assertNotContains('Gift Wrap', $this->optionNames($after, 'packaging'));
        $this->assertContains('Kraft Box', $this->optionNames($after, 'packaging'));

        // A different nursery (no enablement row) still sees Gift Wrap.
        $other = IdentityAccessSeeder::NURSERY_B;
        $b = $this->getJson("/api/v1/config/products/{$this->ids['productUuid']}/configuration?variant={$this->ids['variantS']}&nursery={$other}")->json('data');
        $this->assertContains('Gift Wrap', $this->optionNames($b, 'packaging'));
    }

    public function test_validate_selection_returns_all_violations_at_once(): void
    {
        // For the SMALL variant, submit a selection that breaks several rules:
        //  - pot (required) not selected            → REQUIRED
        //  - packaging (single) has two selections   → SINGLE_SELECT
        //  - accessories gets a pot option (invalid) → INVALID_OPTION
        //  - an unknown group is referenced          → UNKNOWN_GROUP
        $res = $this->postJson("/api/v1/config/products/{$this->ids['productUuid']}/validate-selection", [
            'variant'   => $this->ids['variantS'],
            'selection' => [
                'packaging'   => [$this->ids['giftWrap'], $this->ids['kraftBox']],
                'accessories' => [$this->ids['largePot']],
                'nonexistent' => ['whatever'],
            ],
        ])->assertStatus(200);

        $this->assertFalse($res->json('data.valid'));
        $codes = array_column($res->json('data.violations'), 'code');
        $this->assertContains('REQUIRED', $codes);
        $this->assertContains('SINGLE_SELECT', $codes);
        $this->assertContains('INVALID_OPTION', $codes);
        $this->assertContains('UNKNOWN_GROUP', $codes);
    }

    public function test_a_valid_selection_passes(): void
    {
        $res = $this->postJson("/api/v1/config/products/{$this->ids['productUuid']}/validate-selection", [
            'variant'   => $this->ids['variantS'],
            'selection' => [
                'pot'         => [$this->ids['smallPot']],
                'accessories' => [$this->ids['pebbles'], $this->ids['moss']],
            ],
        ])->assertStatus(200);

        $this->assertTrue($res->json('data.valid'));
        $this->assertSame([], $res->json('data.violations'));
    }

    public function test_configuration_writes_require_config_manage_permission(): void
    {
        $customer = $this->bearer($this->accessToken('customer@plantathome.test'));
        $this->postJson('/api/v1/config/groups', ['code' => 'x', 'name' => 'X'], $customer)->assertStatus(403);
    }
}
