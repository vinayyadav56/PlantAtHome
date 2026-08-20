<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a single customer order, with its own per-item vendor assignment +
 * shipment grouping. The customer-facing model (one order, one timeline); the legacy
 * per-vertical child orders are the compat shadow during the P4 rollout.
 */
class OrderItem extends Model
{
    protected $table = 'order_items';

    public $guarded = [];

    protected $casts = [
        'unit_price'           => 'float',
        'subtotal'             => 'float',
        'order_quantity'       => 'integer',
        'reserved_qty'         => 'integer',
        'eta_days'             => 'integer',
        'vendor_price_snapshot' => 'float',
        'vendor_cost_snapshot' => 'float',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function assignedShop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'assigned_shop_id');
    }

    /**
     * COMPAT pointer, derived by OrderItemService::syncItemShipmentColumn(): the single
     * shipment when this line is wholly in one parcel, NULL when it is split across
     * several. Truth lives in `shipment_items` — use allocations().
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ShipmentItem::class, 'order_item_id');
    }

    /**
     * Units of this line on the parcel it was loaded through. Falls back to the ordered
     * quantity when there is no pivot (the line was not reached via Shipment::items()),
     * which is the pre-split behaviour and correct for an unsplit line.
     */
    public function getShippedQtyAttribute(): int
    {
        $pivotQty = $this->pivot->quantity ?? null;

        return max(1, (int) ($pivotQty ?? $this->order_quantity ?? 1));
    }

    /**
     * Goods value of THIS parcel's share of the line.
     *
     * Prorated from the stored subtotal rather than unit_price x qty, because subtotal
     * can already carry a line-level discount; recomputing from unit price would quietly
     * re-charge it. Falls back to unit_price x qty only when no subtotal was stored.
     */
    public function getShippedSubtotalAttribute(): float
    {
        $ordered = max(1, (int) ($this->order_quantity ?? 1));
        $shipped = $this->shipped_qty;
        $subtotal = $this->subtotal ?? null;

        if ($subtotal !== null) {
            return round((float) $subtotal * ($shipped / $ordered), 2);
        }

        return round((float) ($this->unit_price ?? 0) * $shipped, 2);
    }
}
