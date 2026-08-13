<?php

namespace Marvel\Traits;

use Marvel\Enums\PaymentStatus;
use Marvel\Enums\PaymentGatewayType;
use Marvel\Enums\OrderStatus as OrderStatusEnum;

trait OrderManagementTrait
{
    use OrderStatusManagerWithPaymentTrait;
    use DeliveryPartnerEarningsTrait;

    /**
     * changeOrderStatus
     *
     * @param  mixed $order
     * @param  mixed $status
     * @return void
     */
    /**
     * $authoritative — the caller KNOWS the order reached $status (a courier partner's
     * webhook/reconcile callback). Such a caller may skip rungs, because partners report
     * the truth rather than every intermediate state: Borzo jumps straight to
     * out_for_delivery and a first webhook can land on "delivered". Operator-driven
     * callers leave this false and stay one rung at a time. Forward-only and every
     * terminal rule still applies in both modes.
     */
    public function changeOrderStatus($order, $status, bool $authoritative = false)
    {
        $prev_order_status = $order->order_status;

        // C4 — reject illegal/backward transitions that corrupt money + inventory.
        // CANCELLED/REFUNDED are final; a COMPLETED order may only move to REFUNDED
        // (refunds run through their own atomic flow, not this path). This blocks
        // re-crediting vendor earnings via completed→processing→completed and
        // restoring stock for already-shipped/delivered orders.
        if ($prev_order_status !== $status) {
            $rank = OrderStatusEnum::ranks();
            if (in_array($prev_order_status, [OrderStatusEnum::CANCELLED, OrderStatusEnum::REFUNDED], true)) {
                $illegal = true;
            } elseif ($prev_order_status === OrderStatusEnum::COMPLETED) {
                $illegal = $status !== OrderStatusEnum::REFUNDED;
            } elseif ($status === OrderStatusEnum::CANCELLED) {
                $illegal = false; // cancelling interrupts the ladder — legal from any live rung
            } else {
                // One rung at a time: no skipping (processing→completed would settle a vendor
                // for an order nobody shipped) and no regression. An UNRANKED status on either
                // side (failed/refunded) fails closed — those are written by the payment and
                // refund flows directly and are never earned by advancing the ladder.
                $from = $rank[$prev_order_status] ?? null;
                $to   = $rank[$status] ?? null;
                $illegal = $from === null || $to === null
                    || ($authoritative ? $to <= $from : $to !== $from + 1);
            }
            if ($illegal) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(
                    422,
                    'Invalid order status transition: an order that is ' . $prev_order_status . ' cannot become ' . $status . '.'
                );
            }
        }

        $order->order_status = $status;
        $new_order_status = $order->order_status;

        if ($prev_order_status !== $new_order_status) {
            $payment_gateway_type = isset($order->payment_gateway) ? $order->payment_gateway : PaymentGatewayType::CASH_ON_DELIVERY;
            $usedPaymentGateway = !in_array($payment_gateway_type, [PaymentGatewayType::CASH, PaymentGatewayType::CASH_ON_DELIVERY]);
            $isPaymentSuccess = $order->payment_status === PaymentStatus::SUCCESS;
            if ($usedPaymentGateway) {
                if ($isPaymentSuccess) {
                    $this->manageVendorBalance($order, $new_order_status, $prev_order_status);
                    $this->orderStatusManagementOnPayment($order, $new_order_status, '');
                } else {
                    $this->orderStatusManagementOnPayment($order, $new_order_status, $order->payment_status);
                }
            } else {
                $this->manageVendorBalance($order, $new_order_status, $prev_order_status);
                $this->orderStatusManagementOnCOD($order, $prev_order_status, $new_order_status);
            }
            // Credit the assigned delivery partner on completion (reverse on rollback).
            $this->manageDeliveryPartnerBalance($order, $new_order_status, $prev_order_status);
        }
        $order->save();

        try {
            $children = json_decode($order->children);
        } catch (\Throwable $th) {
            $children = $order->children;
        }
        if (is_array($children) && count($children) && $order->order_status === OrderStatusEnum::CANCELLED) {
            foreach ($order->children as $child_order) {
                $child_order->order_status = $status;
                $child_order->save();
            }
        }

        // A suborder's status changed → roll the parent order status up from its siblings
        // (the least-advanced live leg, or the dominant terminal state once every leg has
        // ended). Keeps the parent a faithful summary of the per-vertical legs.
        if (!empty($order->parent_id)) {
            $this->recomputeParentOrderStatus($order->parent_id);
        }

        return $order;
    }

    /**
     * Recompute a parent order's status from its suborders (children).
     */
    protected function recomputeParentOrderStatus($parentId): void
    {
        // Load the parent without its heavy default eager-loads (customer +
        // products.variation_options) and read the child statuses with a single light
        // pluck — avoids reloading the whole parent subtree on every suborder transition.
        $parent = \Marvel\Database\Models\Order::without('customer', 'products')->find($parentId);
        if (!$parent) {
            return;
        }
        $statuses = \Marvel\Database\Models\Order::where('parent_id', $parentId)
            ->pluck('order_status')->filter()->values();
        if ($statuses->isEmpty()) {
            return;
        }

        $ranks = OrderStatusEnum::ranks();
        // A parent that already ended is final — a late sibling rollup must not reopen it.
        $abandoned = [OrderStatusEnum::CANCELLED, OrderStatusEnum::REFUNDED, OrderStatusEnum::FAILED];
        if (in_array($parent->order_status, $abandoned, true)) {
            return;
        }

        $terminal = array_merge([OrderStatusEnum::COMPLETED], $abandoned);
        if ($statuses->every(fn ($s) => in_array($s, $terminal, true))) {
            // Every leg has ended: report the state most of them landed in (ties resolve to the
            // most successful) instead of collapsing a finished order back to processing.
            $counts = $statuses->countBy();
            $new = collect($terminal)->sortByDesc(fn ($s) => $counts[$s] ?? 0)->first();
        } else {
            // An order is only as far along as its least-advanced LIVE leg — which is how a
            // parent whose legs are all out-for-delivery finally reports out-for-delivery
            // instead of processing. Abandoned legs are out of the race and must not pin it.
            $new = $statuses->reject(fn ($s) => in_array($s, $abandoned, true))
                ->sortBy(fn ($s) => $ranks[$s] ?? 0)->first();
        }

        // Rank floor. CourierService advances the PARENT directly, while this rollup re-runs on
        // every sibling transition — without the floor a slower leg drags a delivered order back
        // to processing, which is why multi-item orders could never show as delivered.
        if (isset($ranks[$new], $ranks[$parent->order_status]) && $ranks[$new] < $ranks[$parent->order_status]) {
            return;
        }

        // The numeric floor cannot see an abandoned destination — cancelled/refunded/failed carry
        // no rank, so `isset()` above is false and every one of them sails past it. A COMPLETED
        // parent with a majority of cancelled siblings would then be laundered into "cancelled",
        // settling money and issuing an invoice for an order that reports as never happening.
        // changeOrderStatus admits completed → refunded and nothing else; honour that here too,
        // because saveQuietly() bypasses the seam that enforces it.
        if (!isset($ranks[$new])
            && $parent->order_status === OrderStatusEnum::COMPLETED
            && $new !== OrderStatusEnum::REFUNDED) {
            return;
        }

        if ($parent->order_status !== $new) {
            $old = $parent->order_status;
            $parent->order_status = $new;
            $parent->saveQuietly();
            // saveQuietly bypasses the Order model's activity-log observer — record explicitly
            // so parent orders don't lose their completion/cancellation events.
            \Marvel\Database\Models\OrderEvent::record($parent->id, 'order.status', [
                'from'   => $old,
                'to'     => $new,
                'rollup' => true,
            ]);

            // P5 stock reservation: the real fulfilment flow flips the parent status HERE
            // (rolled up from its suborders via saveQuietly, which bypasses changeOrderStatus/
            // manageVendorBalance). Commit when the whole order is delivered, release when it
            // is cancelled or rolled back. Flag-gated + idempotent; never breaks the flow.
            try {
                $reserve = new \Marvel\Services\OrderItemService();
                if ($new === OrderStatusEnum::COMPLETED) {
                    $reserve->commitForOrder($parent);
                } elseif ($new === OrderStatusEnum::CANCELLED || $old === OrderStatusEnum::COMPLETED) {
                    $reserve->releaseForOrder($parent);
                }
            } catch (\Throwable $e) {
                // reservation is flag-gated + non-authoritative — swallow
            }
        }
    }
}
