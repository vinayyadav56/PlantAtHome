<?php

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Shared\Infrastructure\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int         $id
 * @property string      $uuid
 * @property int|null    $parent_id
 * @property string      $name
 * @property string      $slug
 * @property string      $path
 * @property int         $depth
 */
class Category extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'catalog_categories';

    protected $fillable = [
        'uuid', 'parent_id', 'name', 'slug', 'path', 'depth', 'sort',
        'status', 'seo_title', 'seo_description', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'depth' => 'integer',
        'sort'  => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}
