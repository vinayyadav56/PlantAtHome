<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One earning event for a delivery partner (commission on a delivery, or an
 * incentive — manual bonus or auto rule). Grouped by earned_at for analytics.
 */
class DeliveryPartnerEarning extends Model
{
    protected $table = 'delivery_partner_earnings';

    public $guarded = [];

    protected $casts = [
        'amount'    => 'float',
        'earned_at' => 'datetime',
    ];

    public function deliveryPartner(): BelongsTo
    {
        return $this->belongsTo(DeliveryPartner::class, 'delivery_partner_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
