<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int    $id
 * @property string $uuid
 * @property int    $inventory_item_id
 * @property string $checkout_session_id
 * @property int    $qty
 * @property Carbon $expires_at
 * @property string $status
 */
class Reservation extends Model
{
    use HasUuid;

    protected $table = 'inv_reservations';

    protected $fillable = [
        'uuid', 'inventory_item_id', 'checkout_session_id', 'qty', 'expires_at', 'status',
    ];

    protected $casts = [
        'qty'        => 'integer',
        'expires_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
