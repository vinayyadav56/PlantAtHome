<?php

namespace Marvel\Services\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Accounting\AccountingAuditLog;
use Marvel\Database\Models\VendorAdjustment;
use Marvel\Database\Models\VendorLedgerEntry;

/**
 * Manual vendor adjustments (spec §25): a credit raises what we owe, a debit lowers it. Every
 * adjustment is a journal + sub-ledger row on approval — never a direct balance edit.
 * Amounts at/above settings.accounting.adjustment_approval_threshold need a second admin.
 */
class VendorAdjustmentService
{
    public function create(int $shopId, string $type, string $amount, string $reason, ?string $reference, ?string $effectiveDate, ?string $actor = null): VendorAdjustment
    {
        if (!in_array($type, ['credit', 'debit'], true)) {
            throw new \InvalidArgumentException('Adjustment type must be credit or debit.');
        }
        $money = MoneyBridge::toMoney($amount);
        if ($money->isNegative() || $money->isZero()) {
            throw new \InvalidArgumentException('Adjustment amount must be positive.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A reason is required.');
        }
        $threshold = MoneyBridge::toMoney((new AccountingConfig())->adjustmentApprovalThreshold());
        $needsApproval = $money->amountMinor() >= $threshold->amountMinor();

        $adj = VendorAdjustment::create([
            'shop_id' => $shopId, 'type' => $type, 'amount' => $money->toDecimal(), 'reason' => mb_substr($reason, 0, 500),
            'reference' => $reference, 'effective_date' => ($effectiveDate ? Carbon::parse($effectiveDate) : Carbon::today())->toDateString(),
            'status' => 'pending_approval', 'requires_approval' => $needsApproval, 'created_by' => $actor,
        ]);
        AccountingAuditLog::record('vendor_adjustment', $adj->id, 'created', null, $adj->only(['shop_id', 'type', 'amount', 'reason', 'requires_approval']), $reason, $reference, $actor);

        return $needsApproval ? $adj : $this->approve($adj, $actor ?? 'system', 'auto-approved (below threshold)');
    }

    public function approve(VendorAdjustment $adjustment, ?string $actor = null, ?string $note = null): VendorAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor, $note) {
            $a = VendorAdjustment::whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            if ($a->status !== 'pending_approval') {
                return $a; // already decided — idempotent
            }
            if ($a->requires_approval && $actor && $a->created_by && $actor === $a->created_by) {
                throw new \RuntimeException('A material adjustment must be approved by a different admin than its creator.');
            }
            $money = MoneyBridge::toMoney($a->amount);
            $c = new AccountingConfig();
            $isCredit = $a->type === 'credit';
            $lines = $isCredit
                ? [
                    ['account' => $c->accountCode('other_expenses'), 'debit' => $money, 'shop_id' => $a->shop_id, 'description' => 'Vendor adjustment credit: ' . $a->reason],
                    ['account' => $c->accountCode('vendor_payables'), 'credit' => $money, 'shop_id' => $a->shop_id, 'description' => 'Adjustment #' . $a->id],
                ]
                : [
                    ['account' => $c->accountCode('vendor_payables'), 'debit' => $money, 'shop_id' => $a->shop_id, 'description' => 'Adjustment #' . $a->id],
                    ['account' => $c->accountCode('other_operating_revenue'), 'credit' => $money, 'shop_id' => $a->shop_id, 'description' => 'Vendor adjustment debit: ' . $a->reason],
                ];
            $je = (new JournalService())->postLines($lines, [
                'source_type' => 'VENDOR_ADJUSTMENT', 'source_id' => $a->id, 'source_key' => 'ADJUSTMENT:' . $a->id,
                'entry_date' => $a->effective_date->toDateString(), 'reference_type' => 'vendor_adjustment', 'reference_id' => $a->id,
                'description' => ucfirst($a->type) . ' adjustment for vendor #' . $a->shop_id . ': ' . $a->reason, 'metadata' => ['reference' => $a->reference], 'actor' => $actor,
            ]);
            $ledger = VendorLedgerEntry::create([
                'shop_id' => $a->shop_id, 'entry_type' => $isCredit ? 'adjustment_credit' : 'adjustment_debit',
                'amount' => ($isCredit ? '' : '-') . $money->toDecimal(), 'source' => 'manual', 'status' => 'pending',
                'available_at' => Carbon::now(), 'earned_at' => Carbon::now(), 'journal_entry_id' => $je->id,
                'idempotency_key' => 'adjustment:' . $a->id, 'note' => $a->reason . ($a->reference ? ' [' . $a->reference . ']' : ''),
            ]);
            $a->update(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => Carbon::now(), 'decision_note' => $note, 'journal_entry_id' => $je->id, 'ledger_entry_id' => $ledger->id]);
            AccountingAuditLog::record('vendor_adjustment', $a->id, 'approved', ['status' => 'pending_approval'], ['status' => 'approved', 'journal' => $je->entry_number], $note, 'adjustment:' . $a->id, $actor);
            return $a->fresh();
        });
    }

    public function reject(VendorAdjustment $adjustment, string $note, ?string $actor = null): VendorAdjustment
    {
        $a = VendorAdjustment::findOrFail($adjustment->id);
        if ($a->status !== 'pending_approval') {
            return $a;
        }
        $a->update(['status' => 'rejected', 'approved_by' => $actor, 'approved_at' => Carbon::now(), 'decision_note' => $note]);
        AccountingAuditLog::record('vendor_adjustment', $a->id, 'rejected', ['status' => 'pending_approval'], ['status' => 'rejected'], $note, 'adjustment:' . $a->id, $actor);
        return $a->fresh();
    }
}
