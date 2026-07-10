<?php

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sellable size of a plant — the SKU inventory and pricing hang off. Owns no
 * price or stock itself.
 *
 * @property int    $id
 * @property string $uuid
 * @property int    $product_id
 * @property string $sku
 * @property string $status
 */
class ProductVariant extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'catalog_product_variants';

    protected $fillable = [
        'uuid', 'product_id', 'sku', 'size_code', 'name', 'weight_grams',
        'dimensions', 'status', 'sort', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'dimensions'   => 'array',
        'weight_grams' => 'integer',
        'sort'         => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
