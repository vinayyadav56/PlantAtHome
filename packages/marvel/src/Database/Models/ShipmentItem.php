<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One allocation: how many units of an order line travel on one shipment.
 *
 * The source of truth for what is in a parcel. `order_items.shipment_id` is the derived
 * compatibility pointer, not this — see the migration for why.
 */
class ShipmentItem extends Model
{
    protected $table = 'shipment_items';

    public $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
