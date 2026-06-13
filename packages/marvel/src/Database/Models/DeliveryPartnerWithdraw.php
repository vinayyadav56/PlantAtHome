<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryPartnerWithdraw extends Model
{
    use SoftDeletes;

    protected $table = 'delivery_partner_withdraws';

    public $guarded = [];

    protected $casts = [
        'amount' => 'float',
    ];

    public function deliveryPartner(): BelongsTo
    {
        return $this->belongsTo(DeliveryPartner::class, 'delivery_partner_id');
    }
}
