<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Marvel\Database\Models\DeliveryPartner;
use Marvel\Database\Models\DeliveryPartnerBalance;
use Marvel\Database\Models\DeliveryPartnerEarning;
use Marvel\Database\Models\Settings;

/**
 * Auto incentive rules, configured by admin in settings.options.dpIncentives.
 * Evaluated when a delivery is credited; credits incentive ledger rows + balance.
 *
 * Config shape:
 *   dpIncentives: {
 *     perDelivery:  { enabled, amount, dailyTarget }   // ₹amount per delivery beyond N/day
 *     peakHour:     { enabled, amount, startHour, endHour }  // ₹amount within [start,end)
 *     weeklyTarget: { enabled, amount, target }         // ₹amount once when ≥N completed in the week
 *   }
 */
class DeliveryPartnerIncentiveService
{
    private array $cfg;

    public function __construct()
    {
        $settings = Settings::getData();
        $this->cfg = (array) (($settings->options['dpIncentives'] ?? []) ?: []);
    }

    public function evaluateOnDelivery(DeliveryPartner $dp, $order, DeliveryPartnerBalance $balance): void
    {
        if (empty($this->cfg)) {
            return;
        }
        $this->perDeliveryTarget($dp, $order, $balance);
        $this->peakHour($dp, $order, $balance);
        $this->weeklyTarget($dp, $order, $balance);
    }

    /** ₹amount for each delivery once the DP is past their daily target. */
    private function perDeliveryTarget(DeliveryPartner $dp, $order, DeliveryPartnerBalance $balance): void
    {
        $r = (array) ($this->cfg['perDelivery'] ?? []);
        if (empty($r['enabled']) || ($r['amount'] ?? 0) <= 0) {
            return;
        }
        $target = (int) ($r['dailyTarget'] ?? 0);
        $today = $this->deliveryCount($dp->id, Carbon::today(), Carbon::today()->endOfDay());
        if ($today > $target) {
            $this->credit($dp, $order, 'perDelivery', (float) $r['amount'], "Beyond daily target ({$target})", $balance);
        }
    }

    /** ₹amount when the delivery is credited within the peak window. */
    private function peakHour(DeliveryPartner $dp, $order, DeliveryPartnerBalance $balance): void
    {
        $r = (array) ($this->cfg['peakHour'] ?? []);
        if (empty($r['enabled']) || ($r['amount'] ?? 0) <= 0) {
            return;
        }
        $h = (int) Carbon::now()->format('G');
        $start = (int) ($r['startHour'] ?? 0);
        $end   = (int) ($r['endHour'] ?? 0);
        if ($end > $start && $h >= $start && $h < $end) {
            $this->credit($dp, $order, 'peakHour', (float) $r['amount'], "Peak-hour bonus ({$start}:00–{$end}:00)", $balance);
        }
    }

    /** One-time ₹amount when the DP reaches the weekly target (not yet paid this week). */
    private function weeklyTarget(DeliveryPartner $dp, $order, DeliveryPartnerBalance $balance): void
    {
        $r = (array) ($this->cfg['weeklyTarget'] ?? []);
        if (empty($r['enabled']) || ($r['amount'] ?? 0) <= 0) {
            return;
        }
        $target = (int) ($r['target'] ?? 0);
        if ($target <= 0) {
            return;
        }
        $weekStart = Carbon::now()->startOfWeek();
        $weekEnd   = Carbon::now()->endOfWeek();
        $count = $this->deliveryCount($dp->id, $weekStart, $weekEnd);
        if ($count < $target) {
            return;
        }
        $already = DeliveryPartnerEarning::where('delivery_partner_id', $dp->id)
            ->where('source', 'rule:weeklyTarget')
            ->whereBetween('earned_at', [$weekStart, $weekEnd])
            ->exists();
        if (!$already) {
            $this->credit($dp, $order, 'weeklyTarget', (float) $r['amount'], "Weekly target reached ({$target})", $balance);
        }
    }

    /** Count this DP's positive commission (delivery) ledger rows in a window. */
    private function deliveryCount(int $dpId, Carbon $from, Carbon $to): int
    {
        return DeliveryPartnerEarning::where('delivery_partner_id', $dpId)
            ->where('type', 'commission')
            ->where('amount', '>', 0)
            ->whereBetween('earned_at', [$from, $to])
            ->count();
    }

    private function credit(DeliveryPartner $dp, $order, string $key, float $amount, string $note, DeliveryPartnerBalance $balance): void
    {
        if ($amount <= 0) {
            return;
        }
        DeliveryPartnerEarning::create([
            'delivery_partner_id' => $dp->id,
            'order_id'            => $order->id ?? null,
            'type'               => 'incentive',
            'source'             => 'rule:' . $key,
            'amount'             => $amount,
            'note'               => $note,
            'earned_at'          => now(),
        ]);
        $balance->total_earnings  = (float) $balance->total_earnings + $amount;
        $balance->current_balance = (float) $balance->current_balance + $amount;
        $balance->save();
    }
}
