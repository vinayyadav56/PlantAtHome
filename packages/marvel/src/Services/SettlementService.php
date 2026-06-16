<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\SettlementRun;
use Marvel\Database\Models\VendorLedgerEntry;
use Marvel\Database\Models\VendorSettlement;
use Marvel\Database\Models\Withdraw;

/**
 * Per-order T+N settlement. A run collects every pending ledger entry whose hold has
 * passed (available_at <= asOf), groups by vendor, and creates one vendor_settlement
 * (net of refunds) per vendor with a positive balance. A vendor whose net is <= 0 in
 * this window is left pending so the debit carries forward against future sales.
 * Admin "Pay" creates a `withdraws` row (the existing payout instrument).
 */
class SettlementService
{
    public function run(?Carbon $asOf = null, ?int $generatedBy = null): SettlementRun
    {
        $asOf = $asOf ?: Carbon::now();

        return DB::transaction(function () use ($asOf, $generatedBy) {
            $run = SettlementRun::create([
                'run_date'     => $asOf->toDateString(),
                'status'       => 'draft',
                'generated_by' => $generatedBy,
            ]);

            // Lock the eligible rows so two concurrent runs (cron + an admin click) can't
            // both claim the same earnings. The settle UPDATE below is also guarded on
            // status='pending', so only the first run actually flips (and pays) them.
            $entries = VendorLedgerEntry::where('status', 'pending')
                ->whereNotNull('available_at')
                ->where('available_at', '<=', $asOf)
                ->lockForUpdate()
                ->get();

            $total = 0.0;
            $vendorCount = 0;

            foreach ($entries->groupBy('shop_id') as $shopId => $rows) {
                $net = round((float) $rows->sum('amount'), 2);
                if ($net <= 0) {
                    // Net debt this window → carry forward (leave entries pending).
                    continue;
                }

                $settlement = VendorSettlement::create([
                    'settlement_run_id'  => $run->id,
                    'shop_id'            => (int) $shopId,
                    'gross_sales'        => round((float) $rows->sum('product_value'), 2),
                    'total_commission'   => round((float) $rows->sum('commission_amount'), 2),
                    'total_platform_fee' => round((float) $rows->sum('platform_fee'), 2),
                    'total_pg_fee'       => round((float) $rows->sum('pg_fee'), 2),
                    'shipping_revenue'   => round((float) $rows->sum('shipping_revenue'), 2),
                    'refund_adjustments' => round((float) $rows->where('entry_type', 'refund_reversal')->sum('amount'), 2),
                    'net_payable'        => $net,
                    'status'             => 'pending',
                ]);

                // Compare-and-swap: only claim rows still pending (defence even under the lock).
                $claimed = VendorLedgerEntry::whereIn('id', $rows->pluck('id'))
                    ->where('status', 'pending')
                    ->update(['status' => 'settled', 'vendor_settlement_id' => $settlement->id]);
                if ($claimed === 0) {
                    $settlement->delete();
                    continue;
                }

                $total += $net;
                $vendorCount++;
            }

            $run->update(['status' => 'locked', 'total_amount' => round($total, 2), 'vendor_count' => $vendorCount]);
            return $run->fresh('settlements');
        });
    }

    /**
     * Mark a vendor settlement paid by creating a withdraws row (the payout record).
     * Row-locked + status-guarded so a double-click can't create two payouts.
     */
    public function pay(VendorSettlement $settlement): VendorSettlement
    {
        return DB::transaction(function () use ($settlement) {
            $fresh = VendorSettlement::whereKey($settlement->id)->lockForUpdate()->first();
            if (!$fresh || $fresh->status === 'paid') {
                return $fresh ?: $settlement;
            }
            $withdraw = Withdraw::create([
                'shop_id'        => $fresh->shop_id,
                'amount'         => $fresh->net_payable,
                'status'         => 'approved',
                'payment_method' => 'settlement',
                'note'           => 'Settlement run #' . $fresh->settlement_run_id,
            ]);
            $fresh->update([
                'status'      => 'paid',
                'withdraw_id' => $withdraw->id,
                'paid_at'     => Carbon::now(),
            ]);
            return $fresh->fresh();
        });
    }
}
