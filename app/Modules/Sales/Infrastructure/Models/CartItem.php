<?php

namespace App\Modules\Sales\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $uuid
 * @property int    $cart_id
 * @property string $variant_uuid
 * @property string $nursery_id
 * @property array  $config_snapshot
 * @property array  $price_snapshot
 * @property int    $qty
 */
class CartItem extends Model
{
    use HasUuid;

    protected $table = 'sales_cart_items';

    protected $fillable = ['uuid', 'cart_id', 'variant_uuid', 'nursery_id', 'config_snapshot', 'price_snapshot', 'qty'];

    protected $casts = [
        'config_snapshot' => 'array',
        'price_snapshot'  => 'array',
        'qty'             => 'integer',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }
}
