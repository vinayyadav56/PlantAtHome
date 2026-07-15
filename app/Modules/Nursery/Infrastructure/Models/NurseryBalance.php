<?php

namespace App\Modules\Nursery\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One balance row per nursery (created empty with the nursery). Mutated only
 * inside WithdrawalService transactions under a row lock; decimals arrive as
 * strings from the DB — cast to float only at the resource boundary.
 */
class NurseryBalance extends Model
{
    protected $table = 'nursery_balances';

    protected $fillable = [
        'legacy_id', 'nursery_id', 'total_earnings', 'withdrawn_amount',
        'current_balance', 'payment_info',
    ];

    protected $casts = ['payment_info' => 'array'];

    public function nursery(): BelongsTo
    {
        return $this->belongsTo(Nursery::class, 'nursery_id');
    }
}
