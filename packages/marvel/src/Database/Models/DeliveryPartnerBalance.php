<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryPartnerBalance extends Model
{
    protected $table = 'delivery_partner_balances';

    public $guarded = [];

    protected $casts = [
        'payment_info'    => 'json',
        'total_earnings'  => 'float',
        'withdrawn_amount' => 'float',
        'current_balance' => 'float',
    ];

    public function deliveryPartner(): BelongsTo
    {
        return $this->belongsTo(DeliveryPartner::class, 'delivery_partner_id');
    }
}
