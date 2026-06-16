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
    public function changeOrderStatus($order, $status)
    {
        $prev_order_status = $order->order_status;
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

        // A suborder's status changed → roll the parent order status up from its
        // siblings (all completed → completed; all cancelled → cancelled; else
        // processing). Keeps the parent a faithful summary of the per-vertical legs.
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
        $parent = \Marvel\Database\Models\Order::with('children')->find($parentId);
        if (!$parent) {
            return;
        }
        $statuses = $parent->children->pluck('order_status')->filter()->values();
        if ($statuses->isEmpty()) {
            return;
        }

        if ($statuses->every(fn ($s) => $s === OrderStatusEnum::COMPLETED)) {
            $new = OrderStatusEnum::COMPLETED;
        } elseif ($statuses->every(fn ($s) => $s === OrderStatusEnum::CANCELLED)) {
            $new = OrderStatusEnum::CANCELLED;
        } else {
            $new = OrderStatusEnum::PROCESSING;
        }

        if ($parent->order_status !== $new) {
            $old = $parent->order_status;
            $parent->order_status = $new;
            $parent->saveQuietly();

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
