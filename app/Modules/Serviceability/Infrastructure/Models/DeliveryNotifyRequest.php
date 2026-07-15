<?php

namespace App\Modules\Serviceability\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Notify me when you deliver to my pincode" lead (status pending → notified).
 *
 * @property string $pincode
 * @property string $status
 */
class DeliveryNotifyRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_NOTIFIED = 'notified';

    protected $table = 'delivery_notify_requests';

    protected $fillable = ['pincode', 'email', 'phone', 'user_id', 'product_id', 'status'];
}
