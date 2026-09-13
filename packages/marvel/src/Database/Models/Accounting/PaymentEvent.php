<?php

namespace Marvel\Database\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

/** One gateway payment event (capture/refund/failure), unique per gateway payment id + type. */
class PaymentEvent extends Model
{
    protected $table = 'payment_events';
    public $guarded = [];
    protected $casts = [
        'amount'       => 'decimal:2',
        'fee'          => 'decimal:2',
        'tax_on_fee'   => 'decimal:2',
        'payload'      => 'array',
        'received_at'  => 'datetime',
        'processed_at' => 'datetime',
    ];
}
