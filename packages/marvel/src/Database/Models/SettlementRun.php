<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A T+N settlement sweep: turns settle-eligible ledger entries into per-vendor payouts. */
class SettlementRun extends Model
{
    protected $table = 'settlement_runs';

    public $guarded = [];

    protected $casts = [
        'run_date'     => 'date',
        'total_amount' => 'float',
        'vendor_count' => 'integer',
    ];

    public function settlements(): HasMany
    {
        return $this->hasMany(VendorSettlement::class, 'settlement_run_id');
    }
}
