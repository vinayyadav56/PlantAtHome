<?php

namespace App\Modules\Nursery\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A vendor payout request. Status strings match the legacy `withdraws` enum
 * exactly; approved/rejected are terminal (enforced in WithdrawalService).
 *
 * @property int         $id
 * @property string      $uuid
 * @property int         $nursery_id
 * @property string      $status
 * @property string      $amount   decimal string from the DB
 */
class NurseryWithdrawal extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'nursery_withdrawals';

    protected $fillable = [
        'uuid', 'legacy_id', 'nursery_id', 'amount', 'status', 'payment_method',
        'details', 'note', 'decided_by_uuid', 'decided_at', 'idempotency_key',
    ];

    protected $casts = ['decided_at' => 'datetime'];

    public function nursery(): BelongsTo
    {
        return $this->belongsTo(Nursery::class, 'nursery_id');
    }
}
