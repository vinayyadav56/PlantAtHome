<?php

namespace App\Modules\Sales\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string      $uuid
 * @property int         $checkout_id
 * @property string      $amount
 * @property string      $status
 * @property string|null $idempotency_key
 */
class PaymentIntent extends Model
{
    use HasUuid;

    protected $table = 'sales_payment_intents';

    protected $fillable = ['uuid', 'checkout_id', 'amount', 'currency', 'status', 'idempotency_key', 'provider_ref'];

    protected $casts = ['amount' => 'decimal:2'];
}
