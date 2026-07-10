<?php

namespace App\Modules\Sales\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property string      $uuid
 * @property string|null $customer_uuid
 * @property string      $status
 */
class Cart extends Model
{
    use HasUuid;

    protected $table = 'sales_carts';

    protected $fillable = ['uuid', 'customer_uuid', 'city', 'status'];

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class, 'cart_id');
    }
}
