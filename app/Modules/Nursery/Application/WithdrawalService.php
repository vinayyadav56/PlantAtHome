<?php

namespace App\Modules\Nursery\Application;

use App\Modules\Nursery\Domain\Events\WithdrawalDecided;
use App\Modules\Nursery\Domain\WithdrawalStatus;
use App\Modules\Nursery\Infrastructure\Models\Nursery;
use App\Modules\Nursery\Infrastructure\Models\NurseryBalance;
use App\Modules\Nursery\Infrastructure\Models\NurseryLedgerEntry;
use App\Modules\Nursery\Infrastructure\Models\NurseryWithdrawal;
use App\Shared\Application\DomainActionException;
use App\Shared\Events\EventPublisher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Vendor payout use-cases. Money moves only on approve, inside one transaction
 * holding row locks on both the withdrawal and the balance, with a ledger entry
 * per debit — the balance stays reconcilable. Requests are idempotent via the
 * caller-supplied Idempotency-Key.
 */
class WithdrawalService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly EventPublisher $events,
    ) {
    }

    /**
     * File a withdrawal request (pending). Replays with the same idempotency
     * key return the original request instead of double-filing.
     *
     * @throws DomainActionException INVALID_AMOUNT / INSUFFICIENT_BALANCE (422)
     */
    public function request(
        Nursery $nursery,
        float $amount,
        ?string $paymentMethod,
        ?string $details,
        ?string $idempotencyKey,
        string $actorUuid,
    ): NurseryWithdrawal {
        return $this->db->transaction(function () use ($nursery, $amount, $paymentMethod, $details, $idempotencyKey) {
            if ($idempotencyKey !== null) {
                $existing = NurseryWithdrawal::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }
            }

            if ($amount <= 0) {
                throw DomainActionException::unprocessable('Withdrawal amount must be positive.', 'INVALID_AMOUNT', 'amount');
            }

            $balance = NurseryBalance::where('nursery_id', $nursery->id)->lockForUpdate()->first();

            if (! $balance || $amount > (float) $balance->current_balance) {
                throw DomainActionException::unprocessable(
                    'Withdrawal amount exceeds the current balance.',
                    'INSUFFICIENT_BALANCE',
                    'amount',
                );
            }

            return NurseryWithdrawal::create([
                'nursery_id'      => $nursery->id,
                'amount'          => $amount,
                'status'          => WithdrawalStatus::PENDING,
                'payment_method'  => $paymentMethod,
                'details'         => $details,
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }

    /**
     * Admin decision. approve debits the balance + writes a ledger entry;
     * reject/on_hold/processing only move the status. approved/rejected are
     * terminal — deciding again is a 409.
     */
    public function decide(NurseryWithdrawal $withdrawal, string $action, ?string $note, string $actorUuid): NurseryWithdrawal
    {
        $status = match ($action) {
            'approve'    => WithdrawalStatus::APPROVED,
            'reject'     => WithdrawalStatus::REJECTED,
            'on_hold'    => WithdrawalStatus::ON_HOLD,
            'processing' => WithdrawalStatus::PROCESSING,
            default      => throw DomainActionException::unprocessable('Unknown decision action.', 'INVALID_ACTION', 'action'),
        };

        return $this->db->transaction(function () use ($withdrawal, $status, $note, $actorUuid) {
            $fresh = NurseryWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

            if (WithdrawalStatus::isTerminal($fresh->status)) {
                throw DomainActionException::conflict('Withdrawal has already been decided.', 'WITHDRAWAL_ALREADY_DECIDED');
            }

            if ($status === WithdrawalStatus::APPROVED) {
                $this->debitBalance($fresh);
            }

            $fresh->status = $status;
            if ($note !== null) {
                $fresh->note = $note;
            }
            if (WithdrawalStatus::isTerminal($status)) {
                $fresh->decided_by_uuid = $actorUuid;
                $fresh->decided_at = Carbon::now();
            }
            $fresh->save();

            $this->events->publish(new WithdrawalDecided(
                $fresh->uuid,
                $fresh->nursery?->uuid ?? '',
                $fresh->legacy_id,
                $fresh->status,
                (float) $fresh->amount,
                $actorUuid,
            ));

            return $fresh;
        });
    }

    /** Move the approved amount out of the balance, logging the debit. */
    private function debitBalance(NurseryWithdrawal $withdrawal): void
    {
        $balance = NurseryBalance::where('nursery_id', $withdrawal->nursery_id)->lockForUpdate()->first();
        $amount = (float) $withdrawal->amount;

        if (! $balance || $amount > (float) $balance->current_balance) {
            throw DomainActionException::conflict(
                'Balance no longer covers this withdrawal.',
                'INSUFFICIENT_BALANCE',
            );
        }

        $balance->current_balance = round((float) $balance->current_balance - $amount, 2);
        $balance->withdrawn_amount = round((float) $balance->withdrawn_amount + $amount, 2);
        $balance->save();

        NurseryLedgerEntry::create([
            'nursery_id'     => $withdrawal->nursery_id,
            'type'           => 'withdrawal_debit',
            'amount'         => $amount,
            'reference_type' => 'withdrawal',
            'reference_uuid' => $withdrawal->uuid,
        ]);
    }
}
