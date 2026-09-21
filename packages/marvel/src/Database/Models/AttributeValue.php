<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Builder;
use Marvel\Traits\TranslationTrait;

class AttributeValue extends Model
{
    use TranslationTrait, Sluggable;

    protected $table = 'attribute_values';

    public $guarded = [];

    protected $appends = ['translated_languages'];

    protected $casts = [
        'sort_order'      => 'integer',
        'delivery_charge' => 'float',
    ];

    /**
     * Whether the variant-master columns exist yet.
     *
     * Relations order by sort_order, and a deploy runs its migrations after the
     * new code is already serving — so the ordering has to be asked for only
     * once the column it names is actually there.
     */
    public static function hasVariantMaster(): bool
    {
        static $has = null;

        if ($has === null) {
            try {
                $has = \Illuminate\Support\Facades\Schema::hasColumn('attribute_values', 'sort_order');
            } catch (\Throwable $e) {
                $has = false;
            }
        }

        return $has;
    }


    public function scopeWithUniqueSlugConstraints(Builder $query, Model $model): Builder
    {
        return $query->where('language', $model->language);
    }

    /**
     * Return the sluggable configuration array for this model.
     *
     * @return array
     */
    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'value'
            ]
        ];
    }

    /**
     * @return BelongsTo
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }


    /**
     * @return BelongsToMany
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'attribute_product');
    }
}
