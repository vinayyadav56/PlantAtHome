<?php

namespace Marvel\Database\Models;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Cviebrock\EloquentSluggable\Sluggable;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Traits\Excludable;
use Kodeine\Metable\Metable;
use Marvel\Exceptions\MarvelException;
use Marvel\Traits\TranslationTrait;

class Product extends Model
{
    use Sluggable, SoftDeletes, Excludable, Metable, TranslationTrait;

    public $guarded = [];

    protected $table = 'products';
    protected $metaTable = 'products_meta'; //optional.
    // protected $disableFluentMeta = true;
    public $hideMeta = true;


    protected $casts = [
        'image' => 'json',
        'gallery' => 'json',
        'video' => 'json',
    ];

    protected $appends = [
        'ratings',
        'total_reviews',
        'rating_count',
        'my_review',
        'in_wishlist',
        'blocked_dates',
        'translated_languages',
    ];

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
     * Get the user's full name.
     *
     * @return string
     */
    public function getBlockedDatesAttribute()
    {
        return $this->getBlockedDates();
    }

    function getBlockedDates()
    {
        $_blockedDates = $this->fetchBlockedDatesForAProduct();
        $_flatBlockedDates = [];
        foreach ($_blockedDates as $date) {
            $from = Carbon::parse($date->from);
            $to = Carbon::parse($date->to);
            $dateRange = CarbonPeriod::create($from, $to);
            $_blockedDates = $dateRange->toArray();
            unset($_blockedDates[count($_blockedDates) - 1]);
            $_flatBlockedDates =  array_unique(array_merge($_flatBlockedDates, $_blockedDates));
        }
        return $_flatBlockedDates;
    }

    public function fetchBlockedDatesForAProduct()
    {
        return  Availability::where('product_id', $this->id)->where('bookable_type', 'Marvel\Database\Models\Product')->whereDate('to', '>=', Carbon::now())->get();
    }

    /**
     * @return HasOne
     */
    public function plantAttribute(): HasOne
    {
        return $this->hasOne(PlantAttribute::class, 'product_id');
    }

    /**
     * Ordered gallery images (managed from the admin; files on S3).
     * @return HasMany
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'product_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** Products that make up this bundle (parent = bundle, child = plant). */
    public function bundleItems()
    {
        return $this->belongsToMany(Product::class, 'product_inclusions', 'parent_id', 'child_id')
            ->withPivot('quantity', 'sort_order', 'relation')
            ->wherePivot('relation', 'bundle')
            ->orderBy('product_inclusions.sort_order');
    }

    /** Suggested buy-together add-ons for this product (e.g. pots/planters). */
    public function addons()
    {
        return $this->belongsToMany(Product::class, 'product_inclusions', 'parent_id', 'child_id')
            ->withPivot('quantity', 'sort_order', 'relation')
            ->wherePivot('relation', 'addon')
            ->orderBy('product_inclusions.sort_order');
    }

    /**
     * Rebuild this product's bundle items + buy-together add-ons from the admin
     * form. Each entry is an id or {id, quantity}. Pass null to leave unchanged.
     */
    public function syncInclusions(?array $bundleItems, ?array $addons): void
    {
        \Illuminate\Support\Facades\DB::table('product_inclusions')->where('parent_id', $this->id)->delete();

        $rows = [];
        $add = function ($list, $relation) use (&$rows) {
            foreach (array_values($list ?? []) as $i => $item) {
                $cid = is_array($item) ? ($item['id'] ?? $item['child_id'] ?? null) : $item;
                if (!$cid || (int) $cid === (int) $this->id) continue;
                $rows[] = [
                    'parent_id'  => $this->id,
                    'child_id'   => (int) $cid,
                    'relation'   => $relation,
                    'quantity'   => (int) (is_array($item) ? ($item['quantity'] ?? 1) : 1) ?: 1,
                    'sort_order' => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        };
        $add($bundleItems, 'bundle');
        $add($addons, 'addon');

        if ($rows) {
            \Illuminate\Support\Facades\DB::table('product_inclusions')->insert($rows);
        }
    }

    /** Total catalog value of a bundle's items (before the offer price). */
    public function getBundleTotalValueAttribute(): float
    {
        if (!$this->relationLoaded('bundleItems')) {
            return 0.0;
        }
        return (float) $this->bundleItems->sum(function ($p) {
            $unit = (float) ($p->sale_price ?: $p->price);
            $qty  = (int) ($p->pivot->quantity ?: 1);
            return $unit * $qty;
        });
    }

    /**
     * Rebuild the legacy `image` (primary) + `gallery` JSON columns from the
     * product_images rows, so existing Pickbazar components (cards, list
     * endpoint, SEO) keep working without a refactor. The library
     * (product_images) is the source of truth: only rows flagged `in_gallery`
     * are published into `gallery`, and the in-gallery primary becomes the
     * featured `image`. Called after any image mutation (admin management,
     * the fetch pipeline, and product save via reconcilePlantImages()).
     */
    public function syncImageColumns(): void
    {
        $published = $this->images()->get()->filter(fn (ProductImage $img) => (bool) $img->in_gallery)->values();

        if ($published->isEmpty()) {
            $this->forceFill(['image' => null, 'gallery' => null])->saveQuietly();
            return;
        }

        $toAttachment = fn (ProductImage $img) => [
            'id'        => $img->id,
            'original'  => $img->url,
            'thumbnail' => $img->thumbnail_url ?: $img->url,
        ];

        $primary = $published->firstWhere('is_primary', true) ?? $published->first();

        $this->forceFill([
            'image'   => $toAttachment($primary),
            'gallery' => $published->map($toAttachment)->values()->all(),
        ])->saveQuietly();
    }

    /**
     * Reconcile the plant's photo library with the curated set submitted from
     * the product form. Keeps the library (product_images) as the source of
     * truth while honouring what the editor published:
     *   - a submitted photo already in the library  → marked in_gallery=true
     *   - a submitted photo NOT in the library (a local upload) → added as a
     *     new library row (source='upload', in_gallery=true)
     *   - a library photo NOT in the submitted gallery → in_gallery=false
     *     (kept in the library, just unpublished — "remove from gallery but
     *     stays in plant pictures")
     *   - the featured photo → is_primary=true (others cleared)
     * Then re-derives the image/gallery columns.
     *
     * @param array|null $image   featured attachment {id?, original, thumbnail}
     * @param array|null $gallery array of attachments {id?, original, thumbnail}
     */
    public function reconcilePlantImages(?array $image, ?array $gallery): void
    {
        $gallery = is_array($gallery) ? array_values(array_filter($gallery, 'is_array')) : [];
        $featuredUrl = is_array($image) ? ($image['original'] ?? null) : null;

        // Every submitted attachment that should be published (gallery + featured).
        $submitted = $gallery;
        if (is_array($image)) {
            $submitted[] = $image;
        }

        $library = $this->images()->get()->keyBy(fn (ProductImage $img) => $img->url);
        $publishedUrls = [];
        $nextOrder = (int) ($this->images()->max('sort_order')) + 1;

        foreach ($submitted as $att) {
            $url = $att['original'] ?? null;
            if (!$url) {
                continue;
            }
            $publishedUrls[$url] = true;

            if ($library->has($url)) {
                $row = $library->get($url);
                if (!$row->in_gallery) {
                    $row->update(['in_gallery' => true]);
                }
            } else {
                // A local upload (or any URL not in the library yet) → register it.
                $created = $this->images()->create([
                    'url'           => $url,
                    'thumbnail_url' => $att['thumbnail'] ?? null,
                    'in_gallery'    => true,
                    'is_primary'    => false,
                    'sort_order'    => $nextOrder++,
                    'source'        => 'upload',
                ]);
                $library->put($url, $created);
            }
        }

        // Unpublish library rows the editor removed from the gallery (keep the row).
        foreach ($library as $url => $row) {
            $shouldPublish = isset($publishedUrls[$url]);
            if ((bool) $row->in_gallery !== $shouldPublish) {
                $row->update(['in_gallery' => $shouldPublish]);
            }
        }

        // Featured photo → is_primary.
        $this->images()->update(['is_primary' => false]);
        if ($featuredUrl && $library->has($featuredUrl)) {
            $library->get($featuredUrl)->update(['is_primary' => true]);
        }

        $this->syncImageColumns();
    }

    /**
     * @return BelongsTo
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(Type::class, 'type_id');
    }

    /**
     * @return BelongsTo
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /**
     * @return BelongsTo
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    /**
     * @return BelongsTo
     */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class, 'manufacturer_id');
    }

    /**
     * @return BelongsTo
     */
    public function shipping(): BelongsTo
    {
        return $this->belongsTo(Shipping::class, 'shipping_class_id');
    }

    /**
     * @return BelongsToMany
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product');
    }

    /**
     * @return BelongsToMany
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'product_tag');
    }

    /**
     * @return HasMany
     */
    public function variation_options(): HasMany
    {
        return $this->hasMany(Variation::class, 'product_id');
    }

    /**
     * @return belongsToMany
     */
    public function orders(): belongsToMany
    {
        return $this->belongsToMany(Order::class)->withTimestamps();
    }

    /**
     * @return BelongsToMany
     */
    public function variations(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'attribute_product');
    }

    /**
     * @return HasMany
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'product_id');
    }

    /**
     * @return HasMany
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'product_id');
    }

    /**
     * @return HasMany
     */
    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class, 'product_id');
    }

    public function getRatingsAttribute()
    {
        return round($this->reviews()->avg('rating'), 2);
    }

    public function getTotalReviewsAttribute()
    {
        return $this->reviews()->count();
    }

    public function getRatingCountAttribute()
    {
        return $this->reviews()->orderBy('rating', 'DESC')->groupBy('rating')->select('rating', DB::raw('count(*) as total'))->get();
    }

    public function getMyReviewAttribute()
    {
        if (auth()->user() && !empty($this->reviews()->where('user_id', auth()->user()->id)->first())) {
            return $this->reviews()->where('user_id', auth()->user()->id)->get();
        }
        return null;
    }

    public function getInWishlistAttribute()
    {
        if (auth()->user() && !empty($this->wishlists()->where('user_id', auth()->user()->id)->first())) {
            return true;
        }
        return false;
    }

    public function digital_file()
    {
        return $this->morphOne(DigitalFile::class, 'fileable');
    }

    public function availabilities()
    {
        return $this->morphMany(Availability::class, 'bookable');
    }


    /**
     * @return BelongsToMany
     */
    public function dropoff_locations(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'dropoff_location_product', 'product_id', 'resource_id');
    }
    /**
     * @return BelongsToMany
     */
    public function pickup_locations(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'pickup_location_product', 'product_id', 'resource_id');
    }
    /**
     * @return BelongsToMany
     */
    public function deposits(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'deposit_product', 'product_id', 'resource_id');
    }
    /**
     * @return BelongsToMany
     */
    public function persons(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'person_product', 'product_id', 'resource_id');
    }
    /**
     * @return BelongsToMany
     */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'feature_product', 'product_id', 'resource_id');
    }

    /**
     * @return BelongsToMany
     */
    public function flash_sales(): BelongsToMany
    {
        return $this->belongsToMany(FlashSale::class, 'flash_sale_products')->withPivot('flash_sale_id', 'product_id');
    }

    /**
     * flash_sale_requests
     *
     * @return BelongsToMany
     */
    public function flash_sale_requests(): BelongsToMany
    {
        return $this->belongsToMany(FlashSaleRequests::class, "flash_sale_requests_products");
    }

    public function loadRelated($slug, $limit = 10, $language = DEFAULT_LANGUAGE)
    {
        $relatedProducts = [];
        try {
            $product    = $this->where('slug', $slug)->firstOrFail();
            $categories = $product->categories()->pluck('id');

            $relatedProducts = $this->where('language', $language)
                ->whereHas('categories', function ($query) use ($categories) {
                    $query->whereIn('categories.id', $categories);
                })->with('type')->limit($limit)->get();
        } catch (Exception $e) {
            logger($e->getMessage()); // logging the error
        }
        $this->setRelation('related_products', $relatedProducts);
        return $this;
    }
}
