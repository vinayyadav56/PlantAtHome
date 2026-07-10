<?php

namespace Tests\Feature\Pricing;

use App\Modules\Rules\Domain\ActionType;
use App\Modules\Rules\Domain\Operator;
use App\Modules\Rules\Domain\RuleScope;
use App\Modules\Rules\Infrastructure\Models\RuleDefinition;
use Illuminate\Support\Str;

/**
 * Phase 6 acceptance: a configured line prices correctly (vendor override beats
 * base, options summed, GST applied); a data-driven pricing rule discounts; a
 * tampered client price is IGNORED; the breakdown snapshot is complete.
 */
class PricingTest extends PricingTestCase
{
    private string $variant;
    private string $nurseryA = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        $this->variant = (string) Str::uuid();
    }

    private function admin(): array
    {
        return $this->bearer($this->accessToken('admin@plantathome.test'));
    }

    private function base(string $type, string $uuid, float $amount): void
    {
        $this->postJson('/api/v1/pricing/base-prices', ['sellable_type' => $type, 'sellable_uuid' => $uuid, 'amount' => $amount], $this->admin())
            ->assertStatus(201);
    }

    private function quote(array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/pricing/quote', array_merge(['nursery_id' => $this->nurseryA], $body));
    }

    private function pricingRule(array $conditions, string $actionType, array $params): void
    {
        $rule = RuleDefinition::create(['name' => 'p'.Str::random(4), 'scope' => RuleScope::PRICING, 'priority' => 0, 'condition_combinator' => 'all', 'is_active' => true]);
        foreach ($conditions as [$fact, $op, $val]) {
            $rule->conditions()->create(['fact' => $fact, 'operator' => $op, 'value' => ['v' => $val]]);
        }
        $rule->actions()->create(['type' => $actionType, 'params' => $params, 'sort' => 0]);
    }

    /* ── acceptance ────────────────────────────────────────────────────────── */

    public function test_quote_uses_base_price(): void
    {
        $this->base('variant', $this->variant, 100);

        $this->quote(['variant_uuid' => $this->variant])
            ->assertStatus(200)
            ->assertJsonPath('data.subtotal.amount_minor', 10000)
            ->assertJsonPath('data.total.amount_minor', 10000);
    }

    public function test_vendor_override_beats_base_with_fallback(): void
    {
        $this->base('variant', $this->variant, 100);
        // Nursery A override → 80; nursery B has none → base 100.
        $this->postJson('/api/v1/pricing/vendor-overrides', [
            'nursery_id' => $this->nurseryA, 'sellable_type' => 'variant', 'sellable_uuid' => $this->variant, 'amount' => 80,
        ], $this->admin())->assertStatus(201);

        $this->quote(['variant_uuid' => $this->variant])->assertJsonPath('data.total.amount_minor', 8000);
        $this->quote(['variant_uuid' => $this->variant, 'nursery_id' => '22222222-2222-2222-2222-222222222222'])
            ->assertJsonPath('data.total.amount_minor', 10000);
    }

    public function test_options_are_summed_and_qty_multiplies(): void
    {
        $o1 = (string) Str::uuid();
        $o2 = (string) Str::uuid();
        $this->base('variant', $this->variant, 100);
        $this->base('option', $o1, 20);
        $this->base('option', $o2, 30);

        // (100 + 20 + 30) × 2 = 300
        $this->quote(['variant_uuid' => $this->variant, 'options' => [$o1, $o2], 'qty' => 2])
            ->assertJsonPath('data.subtotal.amount_minor', 30000)
            ->assertJsonPath('data.total.amount_minor', 30000);
    }

    public function test_gst_is_applied_from_a_tax_rule(): void
    {
        $category = (string) Str::uuid();
        $this->base('variant', $this->variant, 100);
        $this->postJson('/api/v1/pricing/tax-rules', ['category_uuid' => $category, 'gst_rate' => 18], $this->admin())->assertStatus(201);

        // taxable 100 → gst 18 → total 118
        $res = $this->quote(['variant_uuid' => $this->variant, 'category_uuid' => $category])
            ->assertJsonPath('data.gst.amount_minor', 1800)
            ->assertJsonPath('data.total.amount_minor', 11800);
        $this->assertEquals(18, $res->json('data.gst_rate')); // loose: 18 or 18.0
    }

    public function test_a_pricing_rule_discounts_the_line(): void
    {
        $this->base('variant', $this->variant, 200);
        // subtotal >= 100 → 10% off
        $this->pricingRule([['cart.total', Operator::GTE, 100]], ActionType::APPLY_DISCOUNT, ['kind' => 'percentage', 'value' => 10]);

        $res = $this->quote(['variant_uuid' => $this->variant])->assertStatus(200);
        $this->assertSame(2000, $res->json('data.discount_total.amount_minor')); // 10% of 200 = 20.00
        $this->assertSame(18000, $res->json('data.taxable.amount_minor'));       // 180.00
    }

    public function test_set_free_makes_a_matched_option_free(): void
    {
        $premium = (string) Str::uuid();
        $this->base('variant', $this->variant, 100);
        $this->base('option', $premium, 50);
        $this->pricingRule([], ActionType::SET_FREE, ['option' => $premium]); // unconditional

        $res = $this->quote(['variant_uuid' => $this->variant, 'options' => [$premium]])->assertStatus(200);
        // subtotal 150, premium (50) free → discount 50, taxable 100
        $this->assertSame(5000, $res->json('data.discount_total.amount_minor'));
        $this->assertSame(10000, $res->json('data.taxable.amount_minor'));
    }

    public function test_a_client_posted_price_is_ignored(): void
    {
        $this->base('variant', $this->variant, 100);

        // Attempt to inject a price — server recomputes from base only.
        $res = $this->quote(['variant_uuid' => $this->variant, 'total' => 1, 'amount' => 1, 'price' => 1])->assertStatus(200);
        $this->assertSame(10000, $res->json('data.total.amount_minor')); // 100.00, NOT 1
    }

    public function test_breakdown_is_complete(): void
    {
        $this->base('variant', $this->variant, 100);
        $this->quote(['variant_uuid' => $this->variant])
            ->assertJsonStructure(['data' => [
                'currency', 'qty',
                'unit' => ['variant_price', 'options', 'unit_subtotal'],
                'subtotal', 'discounts', 'discount_total', 'taxable', 'gst_rate', 'gst', 'total',
            ]]);
    }

    /* ── authorization ─────────────────────────────────────────────────────── */

    public function test_base_price_requires_pricing_manage(): void
    {
        $customer = $this->bearer($this->accessToken('customer@plantathome.test'));
        $this->postJson('/api/v1/pricing/base-prices', ['sellable_type' => 'variant', 'sellable_uuid' => $this->variant, 'amount' => 10], $customer)
            ->assertStatus(403);
    }

    public function test_a_nursery_cannot_override_another_nurserys_price(): void
    {
        $ownerA = $this->bearer($this->accessToken('owner.a@plantathome.test'));
        $this->postJson('/api/v1/pricing/vendor-overrides', [
            'nursery_id' => '22222222-2222-2222-2222-222222222222', 'sellable_type' => 'variant', 'sellable_uuid' => $this->variant, 'amount' => 5,
        ], $ownerA)->assertStatus(403);
    }
}
