<?php

namespace App\Modules\Promotions\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string      $code
 * @property string      $type
 * @property string      $value
 * @property bool        $is_active
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 */
class Coupon extends Model
{
    use HasUuid;

    protected $table = 'promo_coupons';

    protected $fillable = [
        'uuid', 'code', 'type', 'value', 'min_subtotal', 'max_discount',
        'usage_limit', 'per_customer_limit', 'used_count', 'valid_from', 'valid_to', 'is_active',
    ];

    protected $casts = [
        'value'        => 'decimal:2',
        'min_subtotal' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'is_active'    => 'boolean',
        'valid_from'   => 'datetime',
        'valid_to'     => 'datetime',
    ];
}
