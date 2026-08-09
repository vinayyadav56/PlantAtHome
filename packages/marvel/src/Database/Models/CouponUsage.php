<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One redemption of a coupon by an order. The (coupon_id, order_id) unique constraint makes
 * recording a redemption idempotent — a retried checkout for the same order is a no-op.
 */
class CouponUsage extends Model
{
    protected $table = 'coupon_usages';

    protected $fillable = ['coupon_id', 'user_id', 'order_id'];

    protected $casts = [
        'coupon_id' => 'integer',
        'user_id'   => 'integer',
        'order_id'  => 'integer',
    ];
}
