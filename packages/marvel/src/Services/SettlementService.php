<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Accounting\AccountingAuditLog;
use Marvel\Database\Models\SettlementRun;
use Marvel\Database\Models\VendorLedgerEntry;
use Marvel\Database\Models\VendorSettlement;
use Marvel\Services\Accounting\MoneyBridge;
use Marvel\Services\Accounting\VendorPaymentService;

/**
 * Per-period vendor settlement (spec §22-23). A run collects every pending ledger row whose
 * T+N hold has passed (delivered + return window, D3), groups by vendor, and creates one
 * vendor_settlements row per vendor with a positive net — opening (carried) + new payable −
 * deductions − refunds ± adjustments = total. A vendor whose net is <= 0 is left pending so
 * the debt carries forward. Settlements then flow pending_approval → approved →
 * (partially_paid) → paid via VendorPaymentService; cancel releases the claimed rows.
 *
 * Concurrency: eligible rows are row-locked and the status flip is a compare-and-swap, so a
 * cron run and an admin click can't both claim the same earnings.
 */
class SettlementService
{
    public const PENDING_APPROVAL = 'pending_approval';
    public const APPROVED = 'approved';
    public const PROCESSING = 'processing';
    public const PARTIALLY_PAID = 'partially_paid';
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    /** Settled-but-not-fully-paid states (money still owed). */
    public const OPEN = ['pending', 'on_hold', self::PENDING_APPROVAL, self::APPROVED, self::PROCESSING, self::PARTIALLY_PAID];

    private const REFUND_TYPES = ['refund_reversal', 'refund', 'return', 'cancellation', 'reversal'];
    private const ADJUSTMENT_TYPES = ['adjustment', 'adjustment_credit', 'adjustment_debit'];
    private const DEDUCTION_TYPES = ['commission', 'platform_fee', 'pg_fee', 'delivery_deduction', 'packaging_deduction', 'penalty'];

    public function run(?Carbon $asOf = null, ?int $generatedBy = null, ?string $cadence = null): SettlementRun
    {
        $asOf = $asOf ?: Carbon::now();

        return DB::transaction(function () use ($asOf, $generatedBy, $cadence) {
            $lastTo = SettlementRun::where('status', 'locked')->max('period_to');
            $periodFrom = $lastTo ? Carbon::parse($lastTo)->addDay()->toDateString() : null;

            $run = SettlementRun::create([
                'run_date'     => $asOf->toDateString(),
                'status'       => 'draft',
                'generated_by' => $generatedBy,
                'period_from'  => $periodFrom,
                'period_to'    => $asOf->toDateString(),
                'cadence'      => $cadence,
            ]);

            // Lock the eligible rows so two concurrent runs (cron + an admin click) can't
            // both claim the same earnings. The settle UPDATE below is also guarded on
            // status='pending', so only the first run actually flips them.
            $entries = VendorLedgerEntry::where('status', 'pending')
                ->whereNotNull('available_at')
                ->where('available_at', '<=', $asOf)
                ->lockForUpdate()
                ->get();

            $total = MoneyBridge::zero();
            $vendorCount = 0;

            foreach ($entries->groupBy('shop_id') as $shopId => $rows) {
                $net = MoneyBridge::sum($rows->pluck('amount'));
                if ($net->isNegative() || $net->isZero()) {
                    continue; // net debt this window → carry forward (rows stay pending)
                }
                $isNew = fn ($r) => $periodFrom === null || ($r->earned_at && $r->earned_at->toDateString() >= $periodFrom);
                $sales = $rows->filter(fn ($r) => in_array($r->entry_type, ['sale', 'order_completed', 'opening_balance'], true) && (float) $r->amount > 0);
                $opening = MoneyBridge::sum($sales->reject($isNew)->pluck('amount'));
                $new = MoneyBridge::sum($sales->filter($isNew)->pluck('amount'));
                $refunds = MoneyBridge::sum($rows->filter(fn ($r) => in_array($r->entry_type, self::REFUND_TYPES, true))->pluck('amount'));
                $adjust = MoneyBridge::sum($rows->filter(fn ($r) => in_array($r->entry_type, self::ADJUSTMENT_TYPES, true))->pluck('amount'));
                $deduct = MoneyBridge::sum($rows->filter(fn ($r) => in_array($r->entry_type, self::DEDUCTION_TYPES, true))->pluck('amount'));

                $settlement = VendorSettlement::create([
                    'settlement_run_id'  => $run->id,
                    'shop_id'            => (int) $shopId,
                    'period_from'        => $periodFrom,
                    'period_to'          => $asOf->toDateString(),
                    'gross_sales'        => round((float) $rows->sum('product_value'), 2),
                    'total_commission'   => round((float) $rows->sum('commission_amount'), 2),
                    'total_platform_fee' => round((float) $rows->sum('platform_fee'), 2),
                    'total_pg_fee'       => round((float) $rows->sum('pg_fee'), 2),
                    'shipping_revenue'   => round((float) $rows->sum('shipping_revenue'), 2),
                    // Null cost/profit means "unknown" — exclude rather than sum as 0 (never overstate profit).
                    'total_cost'         => round((float) $rows->whereNotNull('cost_value')->sum('cost_value'), 2),
                    'total_profit'       => round((float) $rows->whereNotNull('vendor_profit')->sum('vendor_profit'), 2),
                    'total_tax'          => round((float) $rows->sum('tax_amount'), 2),
                    'refund_adjustments' => $refunds->toDecimal(),
                    'opening_payable'    => $opening->toDecimal(),
                    'new_payable'        => $new->toDecimal(),
                    'deductions'         => $deduct->toDecimal(),   // signed (negative = deducted)
                    'refunds'            => $refunds->toDecimal(),  // signed (negative)
                    'adjustments'        => $adjust->toDecimal(),   // signed
                    'total_payable'      => $net->toDecimal(),
                    'net_payable'        => $net->toDecimal(),
                    'amount_paid'        => '0.00',
                    'remaining_payable'  => $net->toDecimal(),
                    'status'             => self::PENDING_APPROVAL,
                ]);

                // Compare-and-swap: only claim rows still pending (defence even under the lock).
                $claimed = VendorLedgerEntry::whereIn('id', $rows->pluck('id'))
                    ->where('status', 'pending')
                    ->update(['status' => 'settled', 'vendor_settlement_id' => $settlement->id]);
                if ($claimed === 0) {
                    $settlement->delete();
                    continue;
                }
                $total = $total->add($net);
                $vendorCount++;
            }

            $run->update(['status' => 'locked', 'total_amount' => $total->toDecimal(), 'vendor_count' => $vendorCount]);
            return $run->fresh('settlements');
        });
    }

    /** pending_approval → approved (spec §22; permission accounting.settlements.approve). */
    public function approve(VendorSettlement $settlement, ?string $actor = null, ?string $note = null): VendorSettlement
    {
        return DB::transaction(function () use ($settlement, $actor, $note) {
            $s = VendorSettlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if (!in_array($s->status, ['pending', self::PENDING_APPROVAL], true)) {
                throw new \RuntimeException('Settlement #' . $s->id . ' is ' . $s->status . '; only pending settlements can be approved.');
            }
            $before = ['status' => $s->status];
            $s->update(['status' => self::APPROVED, 'approved_by' => $actor ? (int) preg_replace('/\D/', '', $actor) ?: null : null, 'approved_at' => Carbon::now(), 'notes' => $note ?? $s->notes]);
            AccountingAuditLog::record('vendor_settlement', $s->id, 'approved', $before, ['status' => self::APPROVED], $note, 'settlement:' . $s->id, $actor);
            return $s->fresh();
        });
    }

    /** Cancel an unpaid settlement: the claimed ledger rows go back to pending for the next sweep. */
    public function cancel(VendorSettlement $settlement, string $reason, ?string $actor = null): VendorSettlement
    {
        return DB::transaction(function () use ($settlement, $reason, $actor) {
            $s = VendorSettlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ((float) $s->amount_paid > 0 || in_array($s->status, [self::PAID, self::PARTIALLY_PAID, self::CANCELLED], true)) {
                throw new \RuntimeException('Settlement #' . $s->id . ' has payments or is ' . $s->status . '; it cannot be cancelled.');
            }
            VendorLedgerEntry::where('vendor_settlement_id', $s->id)->where('status', 'settled')
                ->update(['status' => 'pending', 'vendor_settlement_id' => null]);
            $before = ['status' => $s->status];
            $s->update(['status' => self::CANCELLED, 'cancelled_at' => Carbon::now(), 'notes' => trim(($s->notes ?? '') . "\nCancelled: " . $reason)]);
            AccountingAuditLog::record('vendor_settlement', $s->id, 'cancelled', $before, ['status' => self::CANCELLED], $reason, 'settlement:' . $s->id, $actor);
            return $s->fresh();
        });
    }

    /**
     * Legacy "Pay" (whole remaining amount in one go) — now a full vendor payment through the
     * same journaled path partial payments use. Row-locked + status-guarded inside.
     */
    public function pay(VendorSettlement $settlement, ?string $actor = null): VendorSettlement
    {
        $fresh = VendorSettlement::findOrFail($settlement->id);
        if ($fresh->status === self::PAID) {
            return $fresh;
        }
        if (in_array($fresh->status, ['pending', self::PENDING_APPROVAL], true)) {
            $fresh = $this->approve($fresh, $actor, 'auto-approved by pay');
        }
        $remaining = MoneyBridge::toMoney($fresh->remaining_payable !== null && (float) $fresh->remaining_payable > 0 ? $fresh->remaining_payable : $fresh->net_payable);
        (new VendorPaymentService())->record($fresh, $remaining->toDecimal(), 'settlement', ['notes' => 'Settlement run #' . $fresh->settlement_run_id], $actor);
        return $fresh->fresh();
    }
}
