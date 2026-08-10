<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Marvel\Traits\TranslationTrait;

class Order extends Model
{
    use SoftDeletes;
    use TranslationTrait;


    protected $table = 'orders';

    public $guarded = [];

    protected $casts = [
        'shipping_address'    => 'json',
        'billing_address'     => 'json',
        'payment_intent_info' => 'json',
        'is_pinned'           => 'boolean',
        'pinned_at'           => 'datetime',
        // Delivery Coverage snapshot at order time (see OrderRepository).
        'delivery_coverage'   => 'json',
    ];

    /**
     * Memoised per request: whether the `is_pinned` column exists yet. Deploys
     * run migrations in the background AFTER the app starts serving, so the
     * pinned-first ordering must degrade gracefully until the column lands.
     */
    protected static ?bool $supportsPinning = null;

    protected static function ordersSupportPinning(): bool
    {
        if (static::$supportsPinning === null) {
            static::$supportsPinning = Schema::hasColumn('orders', 'is_pinned');
        }

        return static::$supportsPinning;
    }

    protected $hidden = [
        //        'created_at',
        'updated_at',
        'deleted_at',
        // Per-order secret for guest-order access. Hidden everywhere by default so it
        // never leaks in list/detail payloads; explicitly made visible only on the
        // create response (so the buyer's client can capture it for their order page).
        'tracking_token',
        // Internal fulfilment/assignment columns (populated by the vendor-DP
        // matching engine). Admin-only; not consumed by the order list/detail
        // JSON yet — hidden so they never bloat the already-large list payload.
        'vendor_shop_id',
        'delivery_partner_id',
        'delivery_mode',
        'assignment_status',
        'vendor_cost_total',
        'dp_commission_amount',
    ];

    protected static function boot()
    {
        parent::boot();
        // Pinned orders float to the top everywhere the model is queried
        // (board, list, detail siblings), then most-recent first.
        static::addGlobalScope('order', function (Builder $builder) {
            if (static::ordersSupportPinning()) {
                $builder->orderBy('is_pinned', 'desc')
                    ->orderBy('pinned_at', 'desc');
            }
            $builder->orderBy('created_at', 'desc');
        });

        // Activity log — the ONE seam for created/status/payment events. payment_status
        // and order_status are written from many scattered sites (payment traits, courier
        // rollups, refunds), so the model observer is the only place that sees them all.
        // OrderEvent::record swallows failures; wasChanged() keeps no-op saves silent.
        // NOTE: saveQuietly() bypasses these hooks — the parent-status rollup in
        // OrderManagementTrait records its own event explicitly.
        static::created(function (Order $order) {
            OrderEvent::record($order->id, 'order.created', [
                'order_status'   => $order->order_status,
                'payment_status' => $order->payment_status,
                'parent_id'      => $order->parent_id,
            ]);
        });
        static::updated(function (Order $order) {
            if ($order->wasChanged('order_status')) {
                OrderEvent::record($order->id, 'order.status', [
                    'from' => $order->getOriginal('order_status'),
                    'to'   => $order->order_status,
                ]);
            }
            if ($order->wasChanged('payment_status')) {
                OrderEvent::record($order->id, 'payment.status', [
                    'from' => $order->getOriginal('payment_status'),
                    'to'   => $order->payment_status,
                ]);
            }
        });
    }

    protected $with = ['customer', 'products.variation_options'];

    /**
     * @return belongsToMany
     */
    public function products(): belongsToMany
    {
        return $this->belongsToMany(Product::class)
            ->withPivot('order_quantity', 'unit_price', 'subtotal', 'variation_option_id')
            ->withTimestamps();
    }

    /**
     * @return belongsTo
     */
    public function coupon(): belongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    /**
     * @return belongsTo
     */
    public function customer(): belongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * @return BelongsTo
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /**
     * The vertical (Type: plants/tools/farmbox) a suborder belongs to.
     * @return BelongsTo
     */
    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Type::class, 'vertical_type_id');
    }

    /**
     * @return HasMany
     */
    public function children()
    {
        return $this->hasMany('Marvel\Database\Models\Order', 'parent_id', 'id');
    }

    /** P4: per-line items of this (single customer) order, with per-item vendor assignment. */
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id');
    }

    /** P4: fulfilment units (one per vendor) grouping this order's items. */
    public function shipments()
    {
        return $this->hasMany(Shipment::class, 'order_id', 'id');
    }

    /**
     * @return HasOne
     */
    public function parent_order()
    {
        return $this->hasOne('Marvel\Database\Models\Order', 'id', 'parent_id');
    }

    /**
     * @return HasOne
     */
    public function refund()
    {
        return $this->hasOne(Refund::class, 'order_id');
    }
    /**
     * @return HasOne
     */
    public function wallet_point()
    {
        return $this->hasOne(OrderWalletPoint::class, 'order_id');
    }

    /**
     * @return HasMany
     */
    public function payment_intent()
    {
        return $this->hasMany(PaymentIntent::class);
    }
    
    /**
     * @return HasMany
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
