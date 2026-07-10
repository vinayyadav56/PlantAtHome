<?php

namespace App\Modules\Promotions\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Redemption extends Model
{
    use HasUuid;

    protected $table = 'promo_redemptions';

    protected $fillable = ['uuid', 'coupon_id', 'customer_uuid', 'order_uuid', 'amount', 'at'];

    protected $casts = ['amount' => 'decimal:2', 'at' => 'datetime'];
}
