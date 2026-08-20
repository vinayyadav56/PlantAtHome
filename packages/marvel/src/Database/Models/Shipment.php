<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A fulfilment unit grouping order_items by vendor + mode (internal; never customer-facing). */
class Shipment extends Model
{
    protected $table = 'shipments';

    public $guarded = [];

    protected $casts = [
        'shipping_cost'        => 'float',
        'shipping_revenue'     => 'float',
        'eta_days'             => 'integer',
        'expected_delivery_at' => 'date',
        'cod_amount'           => 'float',
        'shipped_at'           => 'datetime',
        'delivered_at'         => 'datetime',
        'last_status_at'       => 'datetime',
        // Which Porter UAT flow is running on this shipment, and since when. The simulator reads
        // these back to restore itself after a page refresh — the flow used to live only in a
        // module-level Map in the browser.
        'simulation_flow_type'  => 'integer',
        'simulation_started_at' => 'datetime',
        // What this leg was dispatched FROM, frozen at booking. Never recomputed — see the
        // migration: resolving it live meant a vendor address edit rewrote history.
        'pickup_snapshot'       => 'array',
    ];

    /**
     * Legs the courier stack may touch. shipments.delivery_mode = 'self' means
     * the VENDOR fulfils this leg (shops.delivery_mode = 'self' at grouping
     * time) — auto-booking, the undispatched sweep and CourierService::book
     * must all skip it. Closure keeps the OR properly parenthesised.
     */
    public function scopeCourierEligible($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('delivery_mode')->orWhere('delivery_mode', '!=', 'self');
        });
    }

    public function isSelfDelivery(): bool
    {
        return $this->delivery_mode === 'self';
    }

    /**
     * A LIVE booking: a partner order/AWB exists and it isn't cancelled. Such a
     * shipment is SEALED — its items, lane and parcel must not change under a
     * courier that is already carrying it. Cancelling is the only way to unlock
     * (a cancelled booking keeps its provider ids for the audit trail, which is
     * why the status check is part of the predicate).
     */
    public function isLiveBooked(): bool
    {
        return ($this->provider_order_id || $this->awb_number) && $this->status !== 'cancelled';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /**
     * The lines in this parcel, each carrying the ALLOCATED quantity on its pivot.
     *
     * Reads `$it->shipped_qty` / `$it->shipped_subtotal`, never `order_quantity` /
     * `subtotal` — a line split 3 + 2 appears on two shipments, and the ordered figures
     * describe the whole line on both. Using them is how a split parcel declares 5 units
     * twice to the courier and collects COD twice.
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(OrderItem::class, 'shipment_items', 'shipment_id', 'order_item_id')
            ->withPivot(['quantity', 'status'])
            ->withTimestamps();
    }

    /** The allocation rows themselves — for writes; `items()` is the read side. */
    public function allocations(): HasMany
    {
        return $this->hasMany(ShipmentItem::class, 'shipment_id');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ShipmentPackage::class, 'shipment_id');
    }
}
