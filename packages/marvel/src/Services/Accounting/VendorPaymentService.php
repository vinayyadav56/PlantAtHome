<?php

namespace Marvel\Services\Accounting;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Accounting\AccountingAuditLog;
use Marvel\Database\Models\VendorLedgerEntry;
use Marvel\Database\Models\VendorPayment;
use Marvel\Database\Models\VendorSettlement;
use Marvel\Database\Models\Withdraw;
use Marvel\Services\SettlementService;

/**
 * Vendor payments (spec §24): partial payments against an approved settlement, each one
 *   a vendor_payments row (UNIQUE idempotency_key)
 *   + journal  DR Vendor Payables[shop] / CR Bank
 *   + a 'vendor_payment' sub-ledger row (−amount, so Σ ledger stays "what we still owe")
 *   + a legacy withdraws mirror row (the vendor/admin lists already read it)
 * and the settlement's amount_paid / remaining_payable / status move accordingly.
 */
class VendorPaymentService
{
    public function record(VendorSettlement $settlement, string $amount, string $method, array $refs = [], ?string $actor = null, ?string $idempotencyKey = null, ?string $paymentDate = null): VendorPayment
    {
        $money = MoneyBridge::toMoney($amount);
        if ($money->isNegative() || $money->isZero()) {
            throw new \InvalidArgumentException('Payment amount must be positive.');
        }
        if (!in_array($method, VendorPayment::METHODS, true)) {
            throw new \InvalidArgumentException('Unknown payment method: ' . $method);
        }
        $key = $idempotencyKey ?: ('settlement:' . $settlement->id . ':' . ($refs['transaction_reference'] ?? $refs['bank_reference'] ?? uniqid('pay', true)));
        if ($existing = VendorPayment::where('idempotency_key', $key)->first()) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($settlement, $money, $method, $refs, $actor, $key, $paymentDate) {
                $s = VendorSettlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
                if (!in_array($s->status, [SettlementService::APPROVED, SettlementService::PROCESSING, SettlementService::PARTIALLY_PAID], true)) {
                    throw new \RuntimeException('Settlement #' . $s->id . ' is ' . $s->status . '; it must be approved before it can be paid.');
                }
                $remaining = MoneyBridge::toMoney((float) $s->remaining_payable > 0 || (float) $s->amount_paid > 0 ? $s->remaining_payable : $s->net_payable);
                if ($money->amountMinor() > $remaining->amountMinor()) {
                    throw new \RuntimeException('Payment ' . $money->toDecimal() . ' exceeds the remaining payable ' . $remaining->toDecimal() . '.');
                }
                $date = $paymentDate ? Carbon::parse($paymentDate) : Carbon::now();

                $payment = VendorPayment::create([
                    'shop_id'               => $s->shop_id,
                    'vendor_settlement_id'  => $s->id,
                    'amount'                => $money->toDecimal(),
                    'payment_date'          => $date->toDateString(),
                    'payment_method'        => $method,
                    'bank_reference'        => $refs['bank_reference'] ?? null,
                    'transaction_reference' => $refs['transaction_reference'] ?? null,
                    'status'                => 'completed',
                    'notes'                 => $refs['notes'] ?? null,
                    'idempotency_key'       => $key,
                    'created_by'            => $actor,
                ]);

                // Legacy mirror: the existing vendor/admin withdraw lists keep showing payouts.
                $withdraw = Withdraw::create([
                    'shop_id'        => $s->shop_id,
                    'amount'         => (float) $money->toDecimal(),
                    'status'         => 'approved',
                    'payment_method' => 'settlement',
                    'note'           => 'Settlement #' . $s->id . ' · payment #' . $payment->id . ($refs['transaction_reference'] ?? null ? ' · ' . $refs['transaction_reference'] : ''),
                ]);

                $c = new AccountingConfig();
                $je = (new JournalService())->postLines([
                    ['account' => $c->accountCode('vendor_payables'), 'debit' => $money, 'shop_id' => $s->shop_id, 'settlement_id' => $s->id, 'vendor_payment_id' => $payment->id, 'description' => 'Vendor payment #' . $payment->id],
                    ['account' => $c->accountCode('bank'), 'credit' => $money, 'shop_id' => $s->shop_id, 'settlement_id' => $s->id, 'vendor_payment_id' => $payment->id, 'description' => strtoupper($method) . ' ' . ($refs['transaction_reference'] ?? '')],
                ], [
                    'source_type' => 'VENDOR_PAYMENT', 'source_id' => $payment->id, 'source_key' => 'VENDOR_PAYMENT:' . $payment->id,
                    'entry_date' => $date->toDateString(), 'reference_type' => 'vendor_payment', 'reference_id' => $payment->id,
                    'description' => 'Payment to vendor #' . $s->shop_id . ' for settlement #' . $s->id, 'actor' => $actor,
                ]);

                VendorLedgerEntry::create([
                    'shop_id' => $s->shop_id, 'entry_type' => 'vendor_payment', 'amount' => '-' . $money->toDecimal(),
                    'source' => 'manual', 'status' => 'settled', 'available_at' => null, 'earned_at' => Carbon::now(),
                    'vendor_settlement_id' => $s->id, 'vendor_payment_id' => $payment->id, 'journal_entry_id' => $je->id,
                    'idempotency_key' => 'payment:' . $payment->id, 'note' => 'Payment #' . $payment->id . ' (' . $method . ')',
                ]);

                $paid = MoneyBridge::toMoney($s->amount_paid ?? 0)->add($money);
                $left = $remaining->subtract($money);
                $s->update([
                    'amount_paid'       => $paid->toDecimal(),
                    'remaining_payable' => $left->toDecimal(),
                    'status'            => $left->isZero() ? SettlementService::PAID : SettlementService::PARTIALLY_PAID,
                    'paid_at'           => $left->isZero() ? Carbon::now() : $s->paid_at,
                    'withdraw_id'       => $s->withdraw_id ?: $withdraw->id,
                ]);
                $payment->update(['withdraw_id' => $withdraw->id, 'journal_entry_id' => $je->id]);

                AccountingAuditLog::record('vendor_payment', $payment->id, 'recorded', null, ['amount' => $money->toDecimal(), 'method' => $method, 'settlement' => $s->id, 'remaining' => $left->toDecimal()], $refs['notes'] ?? null, $je->source_key, $actor);
                return $payment->fresh();
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' || str_contains(strtolower($e->getMessage()), 'unique')) {
                return VendorPayment::where('idempotency_key', $key)->firstOrFail();
            }
            throw $e;
        }
    }
}
