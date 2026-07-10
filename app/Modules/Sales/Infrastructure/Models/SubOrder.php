<?php

namespace App\Modules\Sales\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sub-order per vendor — the fulfillment unit, hidden from the customer as a
 * separate entity. A nursery only ever sees its own.
 *
 * @property string $uuid
 * @property int    $parent_order_id
 * @property string $nursery_id
 * @property string $status
 * @property array  $totals
 */
class SubOrder extends Model
{
    use HasUuid;

    protected $table = 'sales_sub_orders';

    protected $fillable = ['uuid', 'parent_order_id', 'nursery_id', 'status', 'fulfillment_type', 'totals'];

    protected $casts = ['totals' => 'array'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'parent_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'sub_order_id');
    }
}
