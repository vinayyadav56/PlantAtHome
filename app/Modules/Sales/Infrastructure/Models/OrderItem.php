<?php

namespace App\Modules\Sales\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * A fully-frozen line snapshot — invoices remain reproducible forever.
 *
 * @property string $uuid
 * @property int    $sub_order_id
 * @property string $variant_uuid
 * @property array  $price_snapshot
 * @property int    $qty
 */
class OrderItem extends Model
{
    use HasUuid;

    protected $table = 'sales_order_items';

    protected $fillable = [
        'uuid', 'sub_order_id', 'variant_uuid', 'product_snapshot', 'variant_snapshot',
        'config_snapshot', 'price_snapshot', 'weight_grams', 'qty',
    ];

    protected $casts = [
        'product_snapshot' => 'array',
        'variant_snapshot' => 'array',
        'config_snapshot'  => 'array',
        'price_snapshot'   => 'array',
        'qty'              => 'integer',
    ];
}
