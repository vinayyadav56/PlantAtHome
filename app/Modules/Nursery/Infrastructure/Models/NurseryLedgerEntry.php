<?php

namespace App\Modules\Nursery\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only money movement against a nursery balance. Never updated or
 * deleted — the balance stays reconcilable by summing its entries.
 */
class NurseryLedgerEntry extends Model
{
    public const UPDATED_AT = null; // append-only: created_at only

    protected $table = 'nursery_ledger_entries';

    protected $fillable = [
        'nursery_id', 'type', 'amount', 'reference_type', 'reference_uuid', 'note',
    ];

    public function nursery(): BelongsTo
    {
        return $this->belongsTo(Nursery::class, 'nursery_id');
    }
}
