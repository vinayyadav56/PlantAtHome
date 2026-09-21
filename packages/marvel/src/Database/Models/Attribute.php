<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Marvel\Exceptions\MarvelException;
use Marvel\Traits\TranslationTrait;

class Attribute extends Model
{
    use Sluggable, TranslationTrait;

    protected $table = 'attributes';

    protected $appends = ['translated_languages'];

    public $guarded = [];

    /**
     * Return the sluggable configuration array for this model.
     *
     * @return array
     */
    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'name'
            ]
        ];
    }

    public function scopeWithUniqueSlugConstraints(Builder $query, Model $model): Builder
    {
        return $query->where('language', $model->language);
    }


    /**
     * @return HasMany
     */
    public function values(): HasMany
    {
        $relation = $this->hasMany(AttributeValue::class, 'attribute_id');

        // Small -> Medium -> Large, not insertion order.
        return AttributeValue::hasVariantMaster()
            ? $relation->orderBy('sort_order')->orderBy('id')
            : $relation;
    }

    /**
     * @return BelongsToMany
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }
}
