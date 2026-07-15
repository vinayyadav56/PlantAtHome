<?php

namespace App\Modules\Nursery\Http\Resources;

use App\Modules\Nursery\Infrastructure\Models\NurseryWithdrawal;

/** Wire shape of a withdrawal request. UUIDs only; amount as float. */
final class WithdrawalResource
{
    public static function make(NurseryWithdrawal $withdrawal): array
    {
        $nursery = $withdrawal->nursery;

        return [
            'uuid'           => $withdrawal->uuid,
            'legacy_id'      => $withdrawal->legacy_id,
            'amount'         => (float) $withdrawal->amount,
            'status'         => $withdrawal->status,
            'payment_method' => $withdrawal->payment_method,
            'details'        => $withdrawal->details,
            'note'           => $withdrawal->note,
            'decided_at'     => $withdrawal->decided_at?->toIso8601String(),
            'created_at'     => $withdrawal->created_at?->toIso8601String(),
            'nursery'        => $nursery ? [
                'uuid'      => $nursery->uuid,
                'name'      => $nursery->name,
                'slug'      => $nursery->slug,
                'legacy_id' => $nursery->legacy_id,
            ] : null,
        ];
    }
}
