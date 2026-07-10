<?php

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable stock ledger — every on-hand change is a signed delta row. On-hand
 * is reconcilable as the sum of an item's movements.
 *
 * @property int    $id
 * @property string $uuid
 * @property int    $inventory_item_id
 * @property int    $delta
 * @property string $reason
 */
class Movement extends Model
{
    use HasUuid;

    protected $table = 'inv_movements';

    protected $fillable = ['uuid', 'inventory_item_id', 'delta', 'reason', 'ref_type', 'ref_id'];

    protected $casts = ['delta' => 'integer'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
