<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One (possibly partial) payment to a vendor against a settlement — always journaled (spec §24). */
class VendorPayment extends Model
{
    public const METHODS = ['bank_transfer', 'neft', 'rtgs', 'imps', 'upi', 'other', 'settlement'];

    protected $table = 'vendor_payments';
    public $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'payment_date' => 'date:Y-m-d'];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(VendorSettlement::class, 'vendor_settlement_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(\Marvel\Database\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }
}
