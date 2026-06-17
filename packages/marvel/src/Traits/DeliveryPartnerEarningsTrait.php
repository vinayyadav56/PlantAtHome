<?php

namespace Marvel\Traits;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\DeliveryPartner;
use Marvel\Database\Models\DeliveryPartnerBalance;
use Marvel\Database\Models\DeliveryPartnerEarning;
use Marvel\Enums\OrderStatus;
use Marvel\Services\DeliveryPartnerIncentiveService;

/**
 * Credits a delivery partner when their assigned order is COMPLETED (and reverses
 * on a status rollback). Mirrors the vendor flow in
 * OrderStatusManagerWithPaymentTrait::manageVendorBalance/updateBalanceShop, but
 * the amount comes from the DP's own commission config (per_order/per_plant,
 * fixed/percentage; the courier_* variant when delivery_mode = courier_dp).
 */
trait DeliveryPartnerEarningsTrait
{
    public function manageDeliveryPartnerBalance($order, $new_status, $prev_status): void
    {
        if (empty($order->delivery_partner_id)) {
            return;
        }
        if ($new_status === OrderStatus::COMPLETED && $prev_status !== OrderStatus::COMPLETED) {
            $this->creditDeliveryPartner($order, 'add');
        } elseif ($prev_status === OrderStatus::COMPLETED && $new_status !== OrderStatus::COMPLETED) {
            $this->creditDeliveryPartner($order, 'deduct');
        }
    }

    protected function creditDeliveryPartner($order, string $action = 'add'): void
    {
        $dp = DeliveryPartner::find($order->delivery_partner_id);
        if (!$dp) {
            return;
        }

        // Idempotency on (order, commission): a COMPLETED→PROCESSING→COMPLETED toggle, a
        // replayed webhook, or two reversal paths racing must not credit/reverse twice.
        // $net = the commission currently outstanding for this order (credits − reversals).
        $net = (float) DeliveryPartnerEarning::where('order_id', $order->id)
            ->where('type', 'commission')
            ->sum('amount');

        if ($action === 'add') {
            if ($net > 0) {
                return; // already credited and not since reversed
            }
            $amount = $this->computeDpCommission($order, $dp);
        } else { // deduct / reverse
            if ($net <= 0) {
                return; // nothing outstanding to reverse
            }
            $amount = -$net; // reverse EXACTLY what stands, regardless of config drift
        }

        $balance = DeliveryPartnerBalance::firstOrCreate(['delivery_partner_id' => $dp->id]);
        // Atomic in-DB increment (not a read-modify-write save) so concurrent credits for the
        // same DP can't lose an update.
        DeliveryPartnerBalance::where('delivery_partner_id', $dp->id)->update([
            'total_earnings'  => DB::raw('total_earnings + (' . $amount . ')'),
            'current_balance' => DB::raw('current_balance + (' . $amount . ')'),
        ]);

        // Per-event ledger row (commission; a contra/negative row on reversal) so
        // earnings can be grouped by period + split commission vs incentive.
        DeliveryPartnerEarning::create([
            'delivery_partner_id' => $dp->id,
            'order_id'            => $order->id,
            'type'               => 'commission',
            'source'             => 'delivery',
            'amount'             => $amount,
            'note'               => $action === 'deduct' ? 'Reversed (status rolled back)' : 'Delivery commission',
            'earned_at'          => now(),
        ]);

        // Snapshot on the order (changeOrderStatus saves the order right after).
        $order->dp_commission_amount = $action === 'add' ? abs($amount) : 0;

        // Auto incentive rules fire only on credit (not on reversal).
        if ($action === 'add') {
            try {
                $balance->refresh(); // reflect the atomic increment above before evaluating incentives
                (new DeliveryPartnerIncentiveService())->evaluateOnDelivery($dp, $order, $balance);
            } catch (\Throwable $e) {
                // never let incentive evaluation break order completion
            }
        }
    }

    /** DP commission for an order, from its commission config (courier variant when courier_dp). */
    protected function computeDpCommission($order, DeliveryPartner $dp): float
    {
        $isCourier = ($order->delivery_mode ?? null) === 'courier_dp';
        $basis = $isCourier ? $dp->courier_commission_basis : $dp->commission_basis;
        $type  = $isCourier ? $dp->courier_commission_type  : $dp->commission_type;
        $value = (float) ($isCourier ? $dp->courier_commission_value : $dp->commission_value);

        if ($type === 'percentage') {
            return round(((float) $order->total) * $value / 100, 2);
        }
        // fixed
        if ($basis === 'per_plant') {
            return round($value * max($this->orderQuantity($order), 1), 2);
        }
        return round($value, 2); // per_order fixed
    }

    protected function orderQuantity($order): int
    {
        $products = $order->relationLoaded('products') ? $order->products : $order->products()->get();
        return (int) $products->sum(fn ($p) => (int) (optional($p->pivot)->order_quantity ?? 1));
    }
}
