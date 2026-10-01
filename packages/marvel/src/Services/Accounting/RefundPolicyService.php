<?php

namespace Marvel\Services\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;

/**
 * Whether a refund is ALLOWED. Not how much it is worth — that is RefundService::slices().
 *
 * Keeping the two apart is the point. "Returns within 7 days" is a PlantAtHome business rule;
 * it has no business living inside the Razorpay client or inside the money calculator. Before
 * this class the only eligibility rule the system enforced was ReturnService's hardcoded "the
 * item must be delivered", and `refund_policies` was prose nobody evaluated.
 *
 * DEFAULT-PERMISSIVE BY DESIGN. With no policy row, or a policy whose rule columns are blank,
 * every call returns allowed. That is deliberate: this ships into a live system where refunds
 * already work, and a policy engine that defaults to "no" would silently stop them the moment
 * it deployed. Rules only ever narrow, and only once somebody fills them in.
 *
 *   $d = RefundPolicyService::make()->evaluate($order, [$itemId => 1], 250.00);
 *   if (!$d['allowed']) { … $d['reason'] … }
 *   if ($d['auto_approve']) { … skip manual review … }
 */
class RefundPolicyService
{
    public static function make(): self
    {
        return new self();
    }

    /**
     * The policy that governs this order: the vendor's own if it has an approved one, else the
     * platform-wide policy (shop_id NULL). Null when neither exists ⇒ no rules ⇒ allowed.
     */
    public function policyFor(Order $order): ?object
    {
        if (!Schema::hasTable('refund_policies') || !Schema::hasColumn('refund_policies', 'return_window_days')) {
            return null; // migrations lag the code on Railway/EC2 — behave as unconfigured
        }

        $base = fn () => DB::table('refund_policies')
            ->where('status', 'approved')
            ->whereNull('deleted_at');

        if ($order->shop_id) {
            $own = $base()->where('shop_id', $order->shop_id)->orderByDesc('id')->first();
            if ($own) {
                return $own;
            }
        }

        return $base()->whereNull('shop_id')->orderByDesc('id')->first();
    }

    /**
     * @param  array<int,int>  $items  order_item_id => quantity. Empty = the whole order.
     * @return array{allowed:bool, reason:?string, auto_approve:bool, policy_id:?int}
     */
    public function evaluate(Order $order, array $items = [], ?float $amount = null): array
    {
        $policy = $this->policyFor($order);
        $allow = fn (bool $auto = false) => [
            'allowed' => true, 'reason' => null, 'auto_approve' => $auto, 'policy_id' => $policy->id ?? null,
        ];
        $deny = fn (string $why) => [
            'allowed' => false, 'reason' => $why, 'auto_approve' => false, 'policy_id' => $policy->id ?? null,
        ];

        if (!$policy) {
            return $allow();
        }

        // Excluded categories — checked first: a category that is never refundable is not made
        // refundable by being inside a window.
        $excluded = $this->excludedCategoryIds($policy);
        if ($excluded && $items) {
            $blocked = DB::table('category_product')
                ->join('order_items', 'order_items.product_id', '=', 'category_product.product_id')
                ->whereIn('order_items.id', array_keys($items))
                ->whereIn('category_product.category_id', $excluded)
                ->exists();
            if ($blocked) {
                return $deny('Some of these items are in a category that cannot be refunded.');
            }
        }

        // Return window — measured from DELIVERY, not from the order date. An order that took a
        // week to arrive must not burn its own return window in transit.
        $window = $policy->return_window_days ?? null;
        if ($window !== null && (int) $window > 0) {
            $deliveredAt = $this->deliveredAt($order);
            if ($deliveredAt !== null && $deliveredAt->copy()->addDays((int) $window)->isPast()) {
                return $deny('The ' . (int) $window . '-day return window for this order has closed.');
            }
        }

        // Cancellation window — only meaningful BEFORE delivery; afterwards the return window is
        // the rule that applies.
        $cancelHours = $policy->cancellation_window_hours ?? null;
        if ($cancelHours !== null && (int) $cancelHours > 0 && $this->deliveredAt($order) === null) {
            $placed = $order->created_at ? Carbon::parse($order->created_at) : null;
            if ($placed && $placed->copy()->addHours((int) $cancelHours)->isPast()) {
                return $deny('The ' . (int) $cancelHours . '-hour cancellation window for this order has closed.');
            }
        }

        $threshold = $policy->auto_approve_under ?? null;
        $autoApprove = $threshold !== null && $amount !== null && $amount <= (float) $threshold;

        return $allow($autoApprove);
    }

    /** Throws the same MarvelException shape the repository already uses. */
    public function assertAllowed(Order $order, array $items = [], ?float $amount = null): array
    {
        $decision = $this->evaluate($order, $items, $amount);
        if (!$decision['allowed']) {
            throw new \Marvel\Exceptions\MarvelException((string) $decision['reason'], SOMETHING_WENT_WRONG);
        }
        return $decision;
    }

    /** @return array<int,int> */
    private function excludedCategoryIds(object $policy): array
    {
        $raw = $policy->excluded_category_ids ?? null;
        if (!$raw) {
            return [];
        }
        $ids = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
    }

    /**
     * When this order was delivered.
     *
     * Neither orders nor order_items carries a delivery timestamp — `shipments.delivered_at` is
     * the only place it is recorded. For a split order with several vendor shipments this takes
     * the LATEST: the window should close from the last parcel the shopper received, not the
     * first. Null when nothing has been delivered yet, which skips the window check entirely.
     */
    private function deliveredAt(Order $order): ?Carbon
    {
        if (!Schema::hasTable('shipments') || !Schema::hasColumn('shipments', 'delivered_at')) {
            return null;
        }
        $ids = [$order->id];
        if ($order->relationLoaded('children') || $order->children) {
            foreach ($order->children as $child) {
                $ids[] = $child->id;
            }
        }
        $latest = DB::table('shipments')->whereIn('order_id', $ids)->max('delivered_at');

        return $latest ? Carbon::parse($latest) : null;
    }
}
