<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
    ];

    protected $hidden = [
        //        'created_at',
        'updated_at',
        'deleted_at',
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
        // Order by created_at desc
        static::addGlobalScope('order', function (Builder $builder) {
            $builder->orderBy('created_at', 'desc');
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
