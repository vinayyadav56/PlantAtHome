<?php

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Catalog\Domain\ProductStatus;
use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The plant identity — admin-owned. Deliberately carries NO nursery_id; commerce
 * facts (price, stock, availability) live in other contexts keyed by SKU.
 *
 * @property int         $id
 * @property string      $uuid
 * @property int|null    $category_id
 * @property string      $name
 * @property string      $slug
 * @property string      $status
 */
class Product extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'catalog_products';

    protected $fillable = [
        'uuid', 'category_id', 'name', 'slug', 'botanical_name', 'hindi_name',
        'description', 'care', 'status', 'published_at', 'seo_title',
        'seo_description', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'care'         => 'array',
        'published_at' => 'datetime',
    ];

    public function isPublished(): bool
    {
        return $this->status === ProductStatus::PUBLISHED;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id')->orderBy('sort');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class, 'product_id')->orderBy('sort');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(AttributeValue::class, 'product_id');
    }
}
