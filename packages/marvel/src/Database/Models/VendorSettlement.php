<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One vendor's payout within a settlement run (net of refunds); paid via a withdraws row. */
class VendorSettlement extends Model
{
    protected $table = 'vendor_settlements';

    public $guarded = [];

    protected $casts = [
        'gross_sales'        => 'float',
        'total_commission'   => 'float',
        'total_platform_fee' => 'float',
        'total_pg_fee'       => 'float',
        'shipping_revenue'   => 'float',
        'refund_adjustments' => 'float',
        'net_payable'        => 'float',
        'paid_at'            => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SettlementRun::class, 'settlement_run_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(VendorLedgerEntry::class, 'vendor_settlement_id');
    }
}
