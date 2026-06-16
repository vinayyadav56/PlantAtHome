<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single vendor money event (sale / refund_reversal / adjustment). `amount` is the
 * signed net vendor earning; the breakdown columns explain it. Settle-eligible once
 * `available_at` (delivered + N) has passed. See VendorLedgerService / SettlementService.
 */
class VendorLedgerEntry extends Model
{
    protected $table = 'vendor_ledger_entries';

    public $guarded = [];

    protected $casts = [
        'amount'            => 'float',
        'product_value'     => 'float',
        'commission_amount' => 'float',
        'platform_fee'      => 'float',
        'pg_fee'            => 'float',
        'shipping_revenue'  => 'float',
        'available_at'      => 'datetime',
        'earned_at'         => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(VendorSettlement::class, 'vendor_settlement_id');
    }
}
