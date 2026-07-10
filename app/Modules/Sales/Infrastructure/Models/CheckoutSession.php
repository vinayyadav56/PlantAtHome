<?php

namespace App\Modules\Sales\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string      $uuid
 * @property int         $cart_id
 * @property string|null $customer_uuid
 * @property string      $status
 * @property string|null $inventory_session
 */
class CheckoutSession extends Model
{
    use HasUuid;

    protected $table = 'sales_checkout_sessions';

    protected $fillable = ['uuid', 'cart_id', 'customer_uuid', 'status', 'address_snapshot', 'totals', 'inventory_session'];

    protected $casts = ['address_snapshot' => 'array', 'totals' => 'array'];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'id', 'checkout_id');
    }
}
