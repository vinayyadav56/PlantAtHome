<?php

namespace App\Modules\Sales\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The customer-facing PARENT order. Its status is a rollup of its sub-orders —
 * never set directly.
 *
 * @property string      $uuid
 * @property string|null $customer_uuid
 * @property array       $totals
 * @property string      $status
 */
class Order extends Model
{
    use HasUuid;

    protected $table = 'sales_orders';

    protected $fillable = ['uuid', 'customer_uuid', 'checkout_id', 'address_snapshot', 'totals', 'status', 'delivery_fee'];

    protected $casts = ['address_snapshot' => 'array', 'totals' => 'array', 'delivery_fee' => 'decimal:2'];

    public function subOrders(): HasMany
    {
        return $this->hasMany(SubOrder::class, 'parent_order_id');
    }
}
