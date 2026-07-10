<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One inventory row per SKU per vendor. Available = qty_on_hand − qty_reserved.
 *
 * @property int    $id
 * @property string $uuid
 * @property string $sellable_type
 * @property string $sellable_uuid
 * @property string $nursery_id
 * @property int    $qty_on_hand
 * @property int    $qty_reserved
 * @property bool   $track
 */
class InventoryItem extends Model
{
    use HasUuid;

    protected $table = 'inv_items';

    protected $fillable = [
        'uuid', 'sellable_type', 'sellable_uuid', 'nursery_id', 'warehouse_id',
        'qty_on_hand', 'qty_reserved', 'track',
    ];

    protected $casts = [
        'qty_on_hand'  => 'integer',
        'qty_reserved' => 'integer',
        'track'        => 'boolean',
    ];

    public function available(): int
    {
        return max(0, $this->qty_on_hand - $this->qty_reserved);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'inventory_item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class, 'inventory_item_id');
    }
}
