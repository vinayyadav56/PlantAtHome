<?php

namespace Tests\Feature\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Services\Accounting\RefundPolicyService;

/**
 * Eligibility — whether a refund is ALLOWED, which is a different question from what it is
 * worth. Before RefundPolicyService the only rule the system enforced was "the item must be
 * delivered"; `refund_policies` was prose rendered to shoppers and evaluated by nothing.
 *
 * The property that matters most here is the DEFAULT. This ships into a live system where
 * refunds already work, so an unconfigured policy must allow everything — a policy engine that
 * defaulted to "no" would stop refunds the moment it deployed.
 */
class RefundPolicyTest extends OrdersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // refund_policies is not in the accounting stub set; only this suite needs it.
        if (!Schema::hasTable('refund_policies')) {
            Schema::create('refund_policies', function ($t) {
                $t->id();
                $t->string('title')->nullable();
                $t->string('slug')->nullable();
                $t->text('description')->nullable();
                $t->string('target')->default('customer');
                $t->string('status')->default('approved');
                $t->unsignedBigInteger('shop_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        $m = require base_path('packages/marvel/database/migrations/2026_10_01_000200_add_rules_to_refund_policies.php');
        $m->up();
    }

    private function policy(array $rules = []): int
    {
        return DB::table('refund_policies')->insertGetId(array_merge([
            'title' => 'Standard', 'slug' => 'standard', 'status' => 'approved',
            'shop_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ], $rules));
    }

    private function delivered(\Marvel\Database\Models\Order $order, string $when): void
    {
        DB::table('shipments')->insert([
            'order_id' => $order->id, 'shop_id' => 11, 'status' => 'delivered',
            'delivered_at' => $when, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_unconfigured_system_allows_every_refund(): void
    {
        $order = $this->s68Order();
        $d = RefundPolicyService::make()->evaluate($order);

        $this->assertTrue($d['allowed']);
        $this->assertNull($d['policy_id']);
    }

    public function test_a_policy_with_no_rules_filled_in_changes_nothing(): void
    {
        $order = $this->s68Order();
        $id = $this->policy();
        $this->delivered($order, now()->subYears(2)->toDateTimeString());

        $d = RefundPolicyService::make()->evaluate($order);

        $this->assertTrue($d['allowed'], 'a blank policy must not start refusing refunds');
        $this->assertSame($id, $d['policy_id']);
    }

    public function test_a_closed_return_window_refuses_the_refund(): void
    {
        $order = $this->s68Order();
        $this->policy(['return_window_days' => 7]);
        $this->delivered($order, now()->subDays(10)->toDateTimeString());

        $d = RefundPolicyService::make()->evaluate($order);

        $this->assertFalse($d['allowed']);
        $this->assertStringContainsString('7-day return window', (string) $d['reason']);
    }

    public function test_an_open_return_window_allows_the_refund(): void
    {
        $order = $this->s68Order();
        $this->policy(['return_window_days' => 7]);
        $this->delivered($order, now()->subDays(2)->toDateTimeString());

        $this->assertTrue(RefundPolicyService::make()->evaluate($order)['allowed']);
    }

    /** An order that took a week in transit must not burn its own return window on the way. */
    public function test_the_window_runs_from_delivery_not_from_the_order_date(): void
    {
        $order = $this->s68Order();
        $order->forceFill(['created_at' => now()->subDays(20)])->saveQuietly();
        $this->policy(['return_window_days' => 7]);
        $this->delivered($order, now()->subDays(1)->toDateTimeString());

        $this->assertTrue(RefundPolicyService::make()->evaluate($order->fresh())['allowed']);
    }

    /** Nothing delivered yet ⇒ no window to be outside of. */
    public function test_an_undelivered_order_is_not_caught_by_the_return_window(): void
    {
        $order = $this->s68Order();
        $this->policy(['return_window_days' => 7]);

        $this->assertTrue(RefundPolicyService::make()->evaluate($order)['allowed']);
    }

    public function test_a_closed_cancellation_window_refuses_an_undelivered_order(): void
    {
        $order = $this->s68Order();
        $order->forceFill(['created_at' => now()->subHours(48)])->saveQuietly();
        $this->policy(['cancellation_window_hours' => 24]);

        $d = RefundPolicyService::make()->evaluate($order->fresh());

        $this->assertFalse($d['allowed']);
        $this->assertStringContainsString('24-hour cancellation window', (string) $d['reason']);
    }

    /** Once delivered, the RETURN window governs — the cancellation window stops applying. */
    public function test_the_cancellation_window_does_not_apply_after_delivery(): void
    {
        $order = $this->s68Order();
        $order->forceFill(['created_at' => now()->subHours(48)])->saveQuietly();
        $this->policy(['cancellation_window_hours' => 24, 'return_window_days' => 30]);
        $this->delivered($order, now()->subDays(1)->toDateTimeString());

        $this->assertTrue(RefundPolicyService::make()->evaluate($order->fresh())['allowed']);
    }

    public function test_an_excluded_category_is_never_refundable(): void
    {
        $order = $this->s68Order();
        $plant = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 101)->value('id');
        DB::table('category_product')->insert(['category_id' => 77, 'product_id' => 101]);
        $this->policy(['excluded_category_ids' => json_encode([77])]);
        $this->delivered($order, now()->toDateTimeString());   // inside every window

        $d = RefundPolicyService::make()->evaluate($order, [$plant => 1]);

        $this->assertFalse($d['allowed']);
        $this->assertStringContainsString('cannot be refunded', (string) $d['reason']);
    }

    public function test_an_item_outside_the_excluded_category_is_unaffected(): void
    {
        $order = $this->s68Order();
        $pot = DB::table('order_items')->where('order_id', $order->id)->where('product_id', 102)->value('id');
        DB::table('category_product')->insert(['category_id' => 77, 'product_id' => 101]); // the OTHER product
        $this->policy(['excluded_category_ids' => json_encode([77])]);

        $this->assertTrue(RefundPolicyService::make()->evaluate($order, [$pot => 1])['allowed']);
    }

    public function test_small_refunds_auto_approve_and_larger_ones_do_not(): void
    {
        $order = $this->s68Order();
        $this->policy(['auto_approve_under' => '500.00']);

        $this->assertTrue(RefundPolicyService::make()->evaluate($order, [], 250.00)['auto_approve']);
        $this->assertTrue(RefundPolicyService::make()->evaluate($order, [], 500.00)['auto_approve'], 'at the threshold is under it');
        $this->assertFalse(RefundPolicyService::make()->evaluate($order, [], 900.00)['auto_approve']);
        // and an unknown amount never auto-approves
        $this->assertFalse(RefundPolicyService::make()->evaluate($order)['auto_approve']);
    }

    /** A vendor's own approved policy wins over the platform default. */
    public function test_a_shops_own_policy_takes_precedence(): void
    {
        $order = $this->s68Order();
        $this->policy(['return_window_days' => 365, 'shop_id' => null]);
        $own = $this->policy(['return_window_days' => 1, 'shop_id' => $order->shop_id]);
        $this->delivered($order, now()->subDays(5)->toDateTimeString());

        $d = RefundPolicyService::make()->evaluate($order);

        $this->assertSame($own, $d['policy_id']);
        $this->assertFalse($d['allowed'], "the shop's 1-day window should govern, not the platform's 365");
    }

    /** A policy still awaiting approval is not yet a rule. */
    public function test_a_pending_policy_is_ignored(): void
    {
        $order = $this->s68Order();
        $this->policy(['return_window_days' => 1, 'status' => 'pending']);
        $this->delivered($order, now()->subDays(30)->toDateTimeString());

        $this->assertTrue(RefundPolicyService::make()->evaluate($order)['allowed']);
    }
}
