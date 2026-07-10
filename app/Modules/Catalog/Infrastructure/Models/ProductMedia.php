<?php

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $id
 * @property string $uuid
 * @property int    $product_id
 * @property string $url
 * @property string $type
 */
class ProductMedia extends Model
{
    use HasUuid;

    protected $table = 'catalog_product_media';

    protected $fillable = ['uuid', 'product_id', 'url', 'type', 'alt', 'sort'];

    protected $casts = ['sort' => 'integer'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
