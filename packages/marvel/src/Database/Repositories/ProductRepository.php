<?php


namespace Marvel\Database\Repositories;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Availability;
use Marvel\Database\Models\DigitalFile;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Resource;
use Marvel\Database\Models\Type;
use Marvel\Database\Models\Variation;
use Marvel\Enums\ProductStatus;
use Marvel\Enums\ProductType;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Spatie\Period\Boundaries;
use Spatie\Period\Period;
use Spatie\Period\Precision;
use Marvel\Enums\Permission;
use Marvel\Events\ProductReviewApproved;
use Marvel\Events\ProductReviewRejected;
use Marvel\Events\DigitalProductUpdateEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Marvel\Exceptions\MarvelException;

class ProductRepository extends BaseRepository
{

    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name'        => 'like',
        'shop_id',
        'status',
        'is_rental',
        'type.slug',
        'dropoff_locations.slug' => 'in',
        'pickup_locations.slug' => 'in',
        'persons.slug' => 'in',
        'deposits.slug' => 'in',
        'features.slug' => 'in',
        'categories.slug' => 'in',
        'tags.slug' => 'in',
        'author.slug',
        'manufacturer.slug' => 'in',
        'min_price' => 'between',
        'max_price' => 'between',
        'price' => 'between',
        'language',
        'metas.key',
        'metas.value',
        'product_type',
        'visibility',
        'noindex',   // sitemap excludes noindexed products via search=noindex:0
        // PlantAtHome — botanical facets, filtered through the plantAttribute
        // HasOne (Prettus turns `plantAttribute.<col>` into whereHas). Multi-
        // select facets use 'in' (comma-separated values). 'in'/'between'
        // entries are ALSO immune to the bare-search smear (RequestCriteria
        // skips them when no key:value is present). pet_friendly is a boolean
        // '=' filter — its value is coerced to 0/1 in boot() below so a string
        // can never reach the tinyint column (MySQL STRICT 500 guard).
        'plantAttribute.sunlight' => 'in',
        'plantAttribute.water_requirement' => 'in',
        'plantAttribute.indoor_outdoor' => 'in',
        'plantAttribute.growth_rate' => 'in',
        'plantAttribute.difficulty_level' => 'in',
        'plantAttribute.height_range' => 'in',
        'plantTerms.slug'                  => 'in',
        'plantAttribute.pet_friendly',
        // Same tinyint shape as pet_friendly. It was never listed, so the Air
        // Purifying collection/filter was a silent no-op (Prettus drops unknown fields).
        'plantAttribute.air_purifying',
        // Size chips filter through the variations axis (Small/Medium/Large);
        // in_stock backs the "in stock only" toggle — the storefronts used to
        // send both as PLAIN query params, which RequestCriteria ignores, so
        // those filters were silent no-ops until they moved into `search`.
        'variations.value' => 'in',
        'in_stock',
        // Master Catalog membership + its listing switch. Listed here or Prettus drops them
        // silently, exactly as it did for the size chips above; BOOLEAN_SEARCH_FIELDS below then
        // coerces the value so a string can never reach the tinyint under MySQL STRICT.
        'is_available_product',
        'listing_enabled',
    ];

    /**
     * Boolean-backed searchable fields. Their values are coerced to 0/1 in
     * boot() before Prettus can compare them — under MySQL STRICT mode a
     * string vs tinyint comparison is a 500, and `is_rental` proved this class
     * of bug exists (it was explicitly filterable long before this list).
     */
    private const BOOLEAN_SEARCH_FIELDS = [
        'plantAttribute.pet_friendly',
        'plantAttribute.air_purifying',
        'in_stock',
        'is_rental',
        'is_available_product',
        'listing_enabled',
    ];

    protected $dataArray = [
        'name',
        'slug',
        'price',
        'sale_price',
        'max_price',
        'min_price',
        'type_id',
        'author_id',
        'language',
        'manufacturer_id',
        'product_type',
        'quantity',
        'unit',
        'is_digital',
        'is_external',
        'external_product_url',
        'external_product_button_text',
        'description',
        'sku',
        'image',
        'size_guide',
        'gallery',
        'video',
        'status',
        'height',
        'length',
        'width',
        'in_stock',
        'is_taxable',
        'hsn_code',
        'tax_rate_id',
        'tax_inclusive',
        'tax_verified',
        'shop_id',
        'sold_quantity',
        'visibility',
        'delivery_charge',
        'seo_title',
        'seo_description',
        'noindex',
    ];
    public function getProductDataArray(): array
    {
        // gtin/mpn come with 2026_10_16_000100, and deploys migrate after the code serves.
        return \Illuminate\Support\Facades\Schema::hasColumns('products', ['gtin', 'mpn'])
            ? [...$this->dataArray, 'gtin', 'mpn']
            : $this->dataArray;
    }

    /**
     * The variant columns a save payload may write. Anything else a client sends back (appends such
     * as blocked_dates, a city flag, product_id) is not written; gtin/mpn once they exist.
     */
    private function variantColumns(): array
    {
        $columns = ['title', 'price', 'sale_price', 'quantity', 'is_disable', 'sku', 'options', 'image', 'is_digital', 'language'];

        return \Illuminate\Support\Facades\Schema::hasColumns('variation_options', ['gtin', 'mpn'])
            ? [...$columns, 'gtin', 'mpn']
            : $columns;
    }

    /**
     * A variable product's price range, from its own enabled variants (a lower sale price counts),
     * never from the client: the admin form derived it by comparing a blank sale price as the
     * cheapest, so every save of a sized plant stored min_price NULL (cards and the product page
     * then read "₹0 – …"). Nothing priced: the stored range stays. Single-seller products are
     * re-mirrored from the seller's rates right after (ProductController::updateProduct).
     */
    private function syncVariantPriceRange($product): void
    {
        if ($product->product_type !== ProductType::VARIABLE) {
            return;
        }
        $rows = \Illuminate\Support\Facades\DB::table('variation_options')->where('product_id', $product->id)->where('is_disable', false)->get(['price', 'sale_price']);
        $effective = $rows->map(function ($v) {
            $price = (float) $v->price;
            $sale = (float) $v->sale_price;

            return $sale > 0 && $sale < $price ? $sale : $price;
        })->filter(fn ($p) => $p > 0);
        $regular = $rows->map(fn ($v) => (float) $v->price)->filter(fn ($p) => $p > 0);
        if ($effective->isEmpty() || $regular->isEmpty()) {
            return;
        }
        $range = ['min_price' => $effective->min(), 'max_price' => $regular->max()];
        if ((float) $product->min_price != $range['min_price'] || (float) $product->max_price != $range['max_price'] || $product->min_price === null) {
            \Illuminate\Support\Facades\DB::table('products')->where('id', $product->id)->update($range);
            $product->forceFill($range);
        }
    }

    /**
     * The chosen axis values as plain ids. The admin sends [{attribute_value_id}], and attach/sync
     * read a list of arrays as {position => pivot attributes}: every save re-attached everything,
     * and a value whose id equalled a list position was rewritten in place (a unique-index 500).
     */
    private function variationValueIds($variations): array
    {
        return collect(is_array($variations) ? $variations : [])
            ->map(fn ($v) => (int) (is_array($v) ? ($v['attribute_value_id'] ?? 0) : $v))
            ->filter()->unique()->values()->all();
    }

    /** A variant row as written: whitelisted columns, a blank title made from its option values ("Red/5 L"). */
    private function variantRow(array $row, array $columns): array
    {
        if (trim((string) ($row['title'] ?? '')) === '') {
            $row['title'] = collect(is_array($row['options'] ?? null) ? $row['options'] : [])->pluck('value')->implode('/');
        }

        return array_intersect_key($row, array_flip($columns));
    }

    public function boot()
    {
        // PlantAtHome — the storefront sends `search` as `key:value` terms joined by ';'
        // (e.g. name:rose;status:publish), but Prettus RequestCriteria applies any BARE
        // (colon-less) value to EVERY $fieldSearchable entry — including the integer/boolean
        // columns shop_id, is_rental, visibility and plantAttribute.pet_friendly. Under MySQL
        // STRICT mode (prod/RDS default) `shop_id = 'rose'` raises an invalid-numeric-value
        // error -> 500. Three bare-value routes exist in RequestCriteria, and ALL are
        // normalized here into the safe `name:<value>` term:
        //   1. a fully colon-less search (?search=rose);
        //   2. a bare SEGMENT inside a compound search (?search=rose;name:x —
        //      parserSearchValue() returns 'rose' and smears it across all '='/like fields);
        //   3. a leading-colon value (?search=:rose — stripos()===0 is FALSY in
        //      parserSearchData(), so the whole string becomes the bare value).
        // Additionally `plantAttribute.pet_friendly` (tinyint) values are coerced to 0/1
        // (true/false/1/0/yes/no accepted); an unparseable value drops the term entirely,
        // so a string comparison can never reach the boolean column.
        $request = app('request');
        $rawSearch = $request->get('search');
        if (is_string($rawSearch) && $rawSearch !== '') {
            $terms = [];
            $bare = [];
            foreach (explode(';', $rawSearch) as $segment) {
                $pos = strpos($segment, ':');
                if ($pos === false || $pos === 0) {
                    // Bare free text (or a leading-colon fragment) → route into name:.
                    $clean = trim(preg_replace('/\s+/', ' ', str_replace(':', ' ', $segment)));
                    if ($clean !== '') {
                        $bare[] = $clean;
                    }
                    continue;
                }
                $field = substr($segment, 0, $pos);
                $value = substr($segment, $pos + 1);
                if ($field === 'categories.slug') {
                    // A category means itself AND everything under it (sub-categories and the
                    // categories placed in it): /c/<parent> lists its children's products.
                    try {
                        $value = implode(',', app(\Marvel\Services\CategoryTree::class)->expandSlugs(explode(',', $value)));
                    } catch (\Throwable $e) {
                        // hierarchy unavailable (missing table mid-deploy): the exact slugs still filter
                    }
                }
                if (in_array($field, self::BOOLEAN_SEARCH_FIELDS, true)) {
                    $bool = filter_var(trim($value), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($bool === null) {
                        continue; // unparseable boolean → drop the term (never string-vs-tinyint)
                    }
                    $value = $bool ? '1' : '0';
                }
                $terms[] = $field . ':' . $value;
            }
            if (!empty($bare)) {
                $text = implode(' ', $bare);
                $nameIdx = null;
                foreach ($terms as $i => $t) {
                    if (str_starts_with($t, 'name:')) {
                        $nameIdx = $i;
                        break;
                    }
                }
                if ($nameIdx !== null) {
                    $terms[$nameIdx] .= ' ' . $text;
                } else {
                    $terms[] = 'name:' . $text;
                }
            }
            $request->merge(['search' => implode(';', $terms)]);
        }

        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }

    /**
     * Configure the Model
     **/
    public function model()
    {
        return Product::class;
    }


    /**
     * processFlashSaleProducts
     *
     * @param  Request $request
     * @return object
     */
    public function processFlashSaleProducts(Request $request, $products_query)
    {
        $user = $request->user();
        switch ($user) {
            case $user->hasPermissionTo(Permission::SUPER_ADMIN):

                // if condition : during deal data build
                // else condition : when he entered into vendor shop & check
                if ($request->searchedByUser === 'super_admin_builder') {

                    $shop_id = $request->shop_id ?? null;
                    $author_id = $request->author ?? null;
                    $manufacturer_id = $request->manufacturer ?? null;

                    $products_query = $products_query->where('in_flash_sale', '=', false)
                        ->where('sale_price', '=', null)
                        ->whereNotIn('id', function ($query) {
                            $query->select('product_id')->from('flash_sale_requests_products');
                        })
                        ->when($shop_id, function ($products_query) use ($shop_id) {
                            return $products_query->where('shop_id', '=',  $shop_id);
                        })
                        ->when($author_id, function ($products_query) use ($author_id) {
                            return $products_query->where('author_id', '=',  $author_id);
                        })
                        ->when($manufacturer_id, function ($products_query) use ($manufacturer_id) {
                            return $products_query->where('manufacturer_id', '=',  $manufacturer_id);
                        });
                } else {
                    $products_query = $products_query->where('in_flash_sale', '=', true)->where('shop_id', '=', $request->shop_id);
                }

                break;

            case $user->hasPermissionTo(Permission::STORE_OWNER):

                // if condition : when he want to see shop specific products
                // else condition : fetched all deal products of vendor's listed all shops. This can be used in vendor root page route
                if ($request->shop_id) {
                    // if : fetching shop product for building flash sale request
                    // else : just seeing which products are selected for flash sale of this shop
                    if ($request->searchedByUser === 'vendor') {
                        $products_query = $products_query->where('in_flash_sale', '=', false)
                            ->where('shop_id', '=', $request->shop_id)
                            ->where('sale_price', '=', null);
                    } else {
                        $products_query = $products_query->where('in_flash_sale', '=', true);
                    }
                } else {
                    $products_query = $products_query->where('in_flash_sale', '=', true)->whereIn('shop_id', $user->shops->pluck('id'));
                }

                break;

            case $user->hasPermissionTo(Permission::STAFF):

                // staff can see only his assigned shop's deals product
                $products_query = $products_query->where('in_flash_sale', '=', true);
                break;


            case $user->hasPermissionTo(Permission::CUSTOMER):

                // customer can see all the products of a deal
                $products_query = $products_query->where('in_flash_sale', '=', true);
                break;
        }

        return $products_query;
    }


    /**
     * storeProduct
     *
     * @param  mixed $request
     * @param  mixed $setting
     * @return void
     */
    /**
     * Coerce empty-string values on numeric/decimal columns to null before an
     * insert/update. The admin form submits "" for an untouched delivery_charge
     * (a decimal(10,2) column), and "" -> decimal is a hard DB cast error under
     * MySQL strict mode -> uncaught 500 on EVERY UI product-create without an
     * explicit delivery charge. This normalizes the whole numeric column set.
     */
    private function normalizeNumericFields(array $data): array
    {
        // Accounting (spec §20): who owns the stock — VENDOR_SUPPLIED (default) or PLATFORM_OWNED.
        if (array_key_exists('ownership_model', $data)) {
            $ok = in_array($data['ownership_model'], ['VENDOR_SUPPLIED', 'PLATFORM_OWNED'], true) && \Illuminate\Support\Facades\Schema::hasColumn('products', 'ownership_model');
            if (!$ok) {
                unset($data['ownership_model']);
            }
        }
        // Nullable numeric columns: a blank string becomes null.
        foreach (['price', 'sale_price', 'min_price', 'max_price', 'quantity', 'sold_quantity', 'tax_rate_id'] as $col) {
            if (array_key_exists($col, $data) && is_string($data[$col]) && trim($data[$col]) === '') {
                $data[$col] = null;
            }
        }
        // delivery_charge is decimal(10,2) NOT NULL default 0.00 — a blank OR null
        // (a form field left untouched) must become 0 ("no delivery charge"),
        // never null (constraint violation) and never "" (decimal cast error).
        if (array_key_exists('delivery_charge', $data)) {
            $dc = $data['delivery_charge'];
            if ($dc === null || (is_string($dc) && trim($dc) === '')) {
                $data['delivery_charge'] = 0;
            }
        }
        return $data;
    }

    public function storeProduct($request, $setting)
    {
        try {
            $data = $this->normalizeNumericFields($request->only($this->getProductDataArray()));
            $data['slug'] = $this->makeSlug($request);

            if ($setting->options["isProductReview"] ?? false) {
                // PUBLISH is a valid create status here because an ADMIN may create straight
                // into the live catalogue. A vendor never reaches this branch with it — the
                // controller overwrites their status with under_review before calling us.
                if (in_array($request->status, [ProductStatus::DRAFT, ProductStatus::UNDER_REVIEW, ProductStatus::PUBLISH], true)) {
                    $data['status'] = $request->status;
                } else {
                    throw new HttpException(406, 'The selected status is invalid.');
                }
            }

            if ($request->product_type == ProductType::SIMPLE) {
                $data['max_price'] = $data['price'];
                $data['min_price'] = $data['price'];
            }

            $product = $this->create($data);

            if (empty($product->slug) || is_numeric($product->slug)) {
                $product->slug = $this->customSlugify($product->name);
            }

            if (isset($request['metas'])) {
                foreach ($request['metas'] as $value) {
                    $metas[$value['key']] = $value['value'];
                    $product->setMeta($metas);
                }
            }

            if (isset($request['categories'])) {
                $product->categories()->attach($request['categories']);
            }
            if (isset($request['dropoff_locations'])) {
                $product->dropoff_locations()->attach($request['dropoff_locations']);
            }
            if (isset($request['pickup_locations'])) {
                $product->pickup_locations()->attach($request['pickup_locations']);
            }
            if (isset($request['persons'])) {
                $product->persons()->attach($request['persons']);
            }
            if (isset($request['features'])) {
                $product->features()->attach($request['features']);
            }
            if (isset($request['deposits'])) {
                $product->deposits()->attach($request['deposits']);
            }
            if (isset($request['tags'])) {
                $product->tags()->attach($request['tags']);
            }
            if (isset($request['variations'])) {
                $product->variations()->attach($this->variationValueIds($request['variations']));
            }
            if (isset($request['variation_options'])) {
                $columns = $this->variantColumns();

                foreach ($request['variation_options']['upsert'] ?? [] as $variation_option) {

                    if (isset($variation_option['is_digital']) && $variation_option['is_digital']) {
                        $file = $variation_option['digital_file'];
                    }

                    $new_variation_option = $product->variation_options()->create($this->variantRow($variation_option, $columns));

                    if (isset($variation_option['is_digital']) && $variation_option['is_digital']) {
                        $digital_file = $new_variation_option->digital_file()->create($file);
                        $new_variation_option->update([
                            'digital_file_tracker' => $digital_file->id
                        ]);
                    }
                }
            }

            if (isset($request['is_digital']) && ($request['is_digital'] === true || $request['is_digital'] === 1) && isset($request['digital_file'])) {
                $digitalFileArray['attachment_id'] = $request['digital_file']['attachment_id'];
                $digitalFileArray['url'] = $request['digital_file']['url'];
                $product->digital_file()->create($digitalFileArray);
            }

            // PlantAtHome: auto-assign a unique SKU when none was supplied (categories
            // are attached above, so the SKU can include the category code).
            $skuGen = app(\Marvel\Services\SkuGenerator::class);
            if (empty($product->sku)) {
                $product->load(['type', 'categories']);
                $product->sku = $skuGen->forProduct($product);
            }

            $product->save();
            // Every variant sent without a SKU, whether or not the product brought its own.
            $skuGen->fillVariantSkus($product);
            $this->syncVariantPriceRange($product);

            // PlantAtHome: register any uploaded Featured/Gallery photos into the
            // plant photo library (product_images) on create. Plants only.
            if (optional($product->type)->slug === 'plants') {
                $product->reconcilePlantImages($data['image'] ?? null, $data['gallery'] ?? null);
                $this->syncPlantAttributeFromRequest($product, $request);
            }

            // Specifications for every vertical (validated by ValidAttributeValues before the save):
            // only the attributes sent are replaced, so a category change never drops saved specs.
            if (isset($request['attribute_values']) && is_array($request['attribute_values'])) {
                app(\Marvel\Services\AttributeValueWriter::class)->write($product, $request['attribute_values']);
            }

            // PlantAtHome: bundle items + buy-together add-ons.
            if (isset($request['bundle_items']) || isset($request['addons'])) {
                $product->syncInclusions($request['bundle_items'] ?? [], $request['addons'] ?? []);
            }

            return $product;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Upsert curated botanical text from a product create/update payload.
     * Only a WHITELIST of keys is written — PlantAttribute is $guarded = []
     * so the raw request must never be mass-assigned. hindi_name is the
     * canonical editor field (already indexed for search); regional_names.hi
     * is kept in sync for future multi-language regional names.
     */
    private function syncPlantAttributeFromRequest($product, $request): void
    {
        $payload = $request['plant_attribute'] ?? null;
        if (!is_array($payload)) {
            return;
        }

        $attributes = [];

        // hindi_name is the canonical editor field; regional_names.hi mirrors it.
        if (array_key_exists('hindi_name', $payload)) {
            $hindi = trim((string) ($payload['hindi_name'] ?? ''));
            $existing = $product->plantAttribute()->first();
            $regional = (array) ($existing->regional_names ?? []);
            if ($hindi !== '') {
                $regional['hi'] = $hindi;
            } else {
                unset($regional['hi']);
            }
            $attributes['hindi_name'] = $hindi !== '' ? $hindi : null;
            $attributes['regional_names'] = $regional ?: null;
        }

        // Curated botanical strings — only keys PRESENT in the payload are written
        // so partial edits never null-out other columns.
        $stringKeys = [
            'scientific_name',
            // The Hindi name in Roman letters ("Tulsi"), shown and searched with hindi_name.
            'hinglish_name',
            'difficulty_level',
            'season',
            'life_cycle',
            'soil_type',
            'humidity',
            'fertilizer_requirement',
            'width_range',
            'care_guide',
            'planting_guide',
            // Free text, not Rule::in: legacy values ("Bright Light") must keep saving.
            'sunlight',
            'water_requirement',
        ];
        foreach ($stringKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $value = trim((string) ($payload[$key] ?? ''));
                $attributes[$key] = $value !== '' ? $value : null;
            }
        }

        foreach (['is_flowering', 'fruit_bearing'] as $key) {
            if (array_key_exists($key, $payload)) {
                $attributes[$key] = $payload[$key] === null
                    ? null
                    : filter_var($payload[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (empty($attributes)) {
            return;
        }

        $product->plantAttribute()->updateOrCreate(
            ['product_id' => $product->id],
            $attributes
        );
    }

    /**
     * The status an update stores (null: leave it as it is). A vendor never publishes their own
     * proposal: saved as a draft it stays a draft, any other save (re)submits it for review, and
     * only an admin's decision publishes it, with product review on or off. Everything else
     * follows checkProductForPublish when product review is on, else the status sent.
     * $notify false: only work out the status (no review notifications), for checks run first.
     */
    public function statusForUpdate($request, $product, $setting, bool $notify = true): ?string
    {
        if ($this->isOwnProposal($request->user(), $product)) {
            return $request->status === ProductStatus::DRAFT ? ProductStatus::DRAFT : ProductStatus::UNDER_REVIEW;
        }
        if ($setting?->options['isProductReview'] ?? false) {
            $status = (string) $this->checkProductForPublish($request, $product, $notify);

            return $status !== '' ? $status : null;
        }

        return $request->filled('status') ? (string) $request->status : null;
    }

    /** A store owner (never an admin) editing a product their own shop proposed. */
    private function isOwnProposal($user, $product): bool
    {
        return $user
            && !$user->hasPermissionTo(Permission::SUPER_ADMIN)
            && $product->proposed_by_shop_id
            && $user->hasPermissionTo(Permission::STORE_OWNER)
            && \Marvel\Database\Models\Shop::where('owner_id', $user->id)->where('id', (int) $product->proposed_by_shop_id)->exists();
    }

    /** $notify false: only work out the status (no review notifications), for checks run before the save. */
    public function checkProductForPublish($request, $product, bool $notify = true)
    {
        $status = '';
        $user = $request->user();
        // An admin decides, checked first: the master shop is created with the first super admin
        // as its owner, and the shop-owner rules below (a vendor's review flow) turned that admin's
        // "Published" on an under-review product into a draft. "Approved" publishes and "Rejected"
        // rejects (the proposer is told); any other status is stored as sent, and a save that
        // sends none keeps the current one (it used to reject the product).
        if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            if ($request->status == ProductStatus::APPROVED) {
                $notify && event(new ProductReviewApproved($product));

                return ProductStatus::PUBLISH;
            }
            if ($request->status == ProductStatus::REJECTED) {
                $notify && event(new ProductReviewRejected($product));

                return ProductStatus::REJECTED;
            }

            return in_array($request->status, [ProductStatus::PUBLISH, ProductStatus::UNPUBLISH, ProductStatus::DRAFT, ProductStatus::UNDER_REVIEW], true)
                ? $request->status
                : $product->status;
        }
        if ($user && data_get($product, 'shop.owner.id') == $user->id) {
            if ($product->status == ProductStatus::DRAFT || $product->status == ProductStatus::UNDER_REVIEW || $product->status == ProductStatus::REJECTED) {
                if ($request->status == ProductStatus::DRAFT) {
                    $status = ProductStatus::DRAFT;
                } elseif ($request->status == ProductStatus::UNDER_REVIEW) {
                    $status = ProductStatus::UNDER_REVIEW;
                } else {
                    $status = ProductStatus::DRAFT;
                }
            } elseif ($product->status == ProductStatus::APPROVED || $product->status == ProductStatus::PUBLISH || $product->status == ProductStatus::UNPUBLISH) {
                if ($request->status == ProductStatus::PUBLISH) {
                    $status = ProductStatus::PUBLISH;
                } elseif ($request->status == ProductStatus::UNPUBLISH) {
                    $status = ProductStatus::UNPUBLISH;
                } else {
                    $status = ProductStatus::UNPUBLISH;
                }
            }
        } else {
            $status = ProductStatus::REJECTED;
        }
        return $status;
    }

    /**
     * updateProduct
     *
     * @param  $request
     * @param  $id
     * @param  $setting
     * @return void
     */
    public function updateProduct($request, $id, $setting)
    {
        try {
            $product = $this->findOrFail($id);

            if (is_array($request['metas'])) {
                foreach ($request['metas'] as $key => $value) {
                    $metas[$value['key']] = $value['value'];
                    $product->setMeta($metas);
                }
            }

            if (isset($request['categories'])) {
                $product->categories()->sync($request['categories']);
            }
            if (isset($request['tags'])) {
                $product->tags()->sync($request['tags']);
            }
            if (isset($request['dropoff_locations'])) {
                $product->dropoff_locations()->sync($request['dropoff_locations']);
            }
            if (isset($request['pickup_locations'])) {
                $product->pickup_locations()->sync($request['pickup_locations']);
            }
            if (isset($request['variations'])) {
                $product->variations()->sync($this->variationValueIds($request['variations']));
            }
            if (isset($request['persons'])) {
                $product->persons()->sync($request['persons']);
            }
            if (isset($request['features'])) {
                $product->features()->sync($request['features']);
            }
            if (isset($request['deposits'])) {
                $product->deposits()->sync($request['deposits']);
            }
            if (isset($request['digital_file'])) {
                $file = $request['digital_file'];
                if (isset($file['id'])) {
                    $product->digital_file()->where('id', $file['id'])->update($file);
                } else {
                    $product->digital_file()->create($file);
                }
            }
            if (isset($request['variation_options'])) {
                $columns = $this->variantColumns();
                if (isset($request['variation_options']['upsert'])) {
                    foreach ($request['variation_options']['upsert'] as $key => $variation) {

                        $variation['sale_price'] = isset($variation['sale_price']) ? $variation['sale_price'] : null;

                        if (isset($variation['is_digital']) && $variation['is_digital']) {

                            $file = $variation['digital_file'];

                            if (isset($variation['id'])) {
                                $product->variation_options()->where('id', $variation['id'])->update($this->variantRow($variation, $columns));

                                try {
                                    $updated_variation = Variation::findOrFail($variation['id']);
                                } catch (Exception $e) {
                                    throw new ModelNotFoundException(NOT_FOUND);
                                }

                                if (TRANSLATION_ENABLED) {
                                    Variation::where('sku', $updated_variation->sku)->where('id', '=', $updated_variation->id)->update([
                                        'price' => $updated_variation->price,
                                        'sale_price' => $updated_variation->sale_price,
                                        'quantity' => $updated_variation->quantity,
                                    ]);
                                }


                                if (isset($updated_variation->digital_file_tracker)) {
                                    if (isset($file['attachment_id'])) {
                                        $updated_variation->digital_file()->where('fileable_id', $updated_variation->id)->update($file);
                                        $updated_digital_file = DigitalFile::where('fileable_id', $updated_variation->id)->first();
                                        $updated_variation->update([
                                            'digital_file_tracker' => $updated_digital_file->id,
                                        ]);
                                    }
                                } else {
                                    $created_digital_file = $updated_variation->digital_file()->create($file);
                                    $updated_variation->update([
                                        'digital_file_tracker' => $created_digital_file->id,
                                    ]);
                                }
                            } else {
                                $new_variation = $product->variation_options()->create($this->variantRow($variation, $columns));
                                $digital_file = $new_variation->digital_file()->create($file);
                                $new_variation->update([
                                    'digital_file_tracker' => $digital_file->id
                                ]);
                            }
                        } else {
                            if (isset($variation['id'])) {
                                $product->variation_options()->where('id', $variation['id'])->update($this->variantRow($variation, $columns));
                            } else {
                                $product->variation_options()->create($this->variantRow($variation, $columns));
                            }
                        }
                    }
                }
                // ValidVariationOptions refused the save if any of these has orders, reviews, seller
                // prices or stock movements, so a failure here is a real error: it is not swallowed.
                $product->variation_options()->whereIn('id', $request['variation_options']['delete'] ?? [])->delete();
            }
            $data = $this->normalizeNumericFields($request->only($this->getProductDataArray()));
            $data['sale_price'] = isset($request['sale_price']) && $request['sale_price'] !== '' ? $request['sale_price'] : null;

            // Who may set which status (a vendor's proposal stays in review): statusForUpdate.
            $status = $this->statusForUpdate($request, $product, $setting);
            if ($status !== null) {
                $data['status'] = $status;
            }

            // The SKU stays: nulling it on every edit lost the one storeProduct assigned, and vendor
            // price sheets find a product by it.
            if ($request->product_type == ProductType::VARIABLE) {
                $data['price'] = NULL;
                $data['sale_price'] = NULL;
            }
            if ($request->product_type == ProductType::SIMPLE) {
                $data['max_price'] = $data['price'];
                $data['min_price'] = $data['price'];
            }

            if (!empty($request->slug) &&  $request->slug != $product->slug) {
                $stringifySlug = $this->makeSlug($request);
                $data['slug'] = $this->makeSlug($request);

                if (TRANSLATION_ENABLED) {
                    $this->where('slug', $product->slug)->where('id', '!=', $product->id)->update([
                        'slug' => $stringifySlug
                    ]);
                }
            }

            // A product's tax setup decides what every customer is charged for it,
            // and it was being silently rewritten by an unrelated edit (see the
            // GetSingleProductResource fix) with no record of who or when. Only
            // the tax keys are diffed — auditing the whole payload would bury them.
            $taxKeys = ['hsn_code', 'tax_rate_id', 'tax_verified', 'tax_inclusive'];
            $taxBefore = $product->only($taxKeys);

            $product->update($data);

            $taxAfter = $product->fresh()?->only($taxKeys) ?? [];
            if ($taxBefore != $taxAfter) {
                \Marvel\Database\Models\Accounting\AccountingAuditLog::record(
                    'product_tax',
                    $product->id,
                    'updated',
                    $taxBefore,
                    $taxAfter,
                    $product->name
                );
            }

            if ($product->product_type === ProductType::SIMPLE) {
                // detach(), not delete(): on a BelongsToMany, delete() removes the
                // attribute_values ROWS — which every other product shares — rather
                // than just this product's pivot links.
                $product->variations()->detach();
                $product->variation_options()->delete();
            }
            $product->save();
            // Variants saved without a SKU get {product SKU}-{suffix}, on every save (not only create).
            app(\Marvel\Services\SkuGenerator::class)->fillVariantSkus($product);
            $this->syncVariantPriceRange($product);

            // PlantAtHome: keep the plant photo library (product_images) in sync with
            // the curated Featured/Gallery the editor submitted. Plants only.
            if (optional($product->type)->slug === 'plants') {
                $product->reconcilePlantImages($data['image'] ?? null, $data['gallery'] ?? null);
                $this->syncPlantAttributeFromRequest($product, $request);
            }

            // Specifications for every vertical (validated by ValidAttributeValues before the save):
            // only the attributes sent are replaced, so a category change never drops saved specs.
            if (isset($request['attribute_values']) && is_array($request['attribute_values'])) {
                app(\Marvel\Services\AttributeValueWriter::class)->write($product, $request['attribute_values']);
            }

            // PlantAtHome: bundle items + buy-together add-ons.
            if (isset($request['bundle_items']) || isset($request['addons'])) {
                $product->syncInclusions($request['bundle_items'] ?? [], $request['addons'] ?? []);
            }

            if (TRANSLATION_ENABLED) {
                $this->where('sku', $product->sku)->where('id', '=',  $product->id)->update([
                    'price' => $product->price,
                    'sale_price' => $product->sale_price,
                    'max_price' => $product->max_price,
                    'min_price' => $product->min_price,
                    'unit' => $product->unit,
                    'quantity' => $product->quantity,
                ]);
            }

            if ($setting->options["enableEmailForDigitalProduct"]) {
                if ($request->product_type == 'variable') {
                    foreach ($request['variation_options']['upsert'] ?? [] as $variation_data) {
                        if (!empty($variation_data['inform_purchased_customer'])) {
                            event(new DigitalProductUpdateEvent($product, $request->user(), [
                                'inform_customer' => $variation_data['inform_purchased_customer'],
                                'update_message' => $variation_data['product_update_message'] ?? ''
                            ]));
                        }
                    }
                } else {
                    if ($request->inform_purchased_customer) {
                        event(new DigitalProductUpdateEvent($product, $request->user(), [
                            'inform_customer' => $request->inform_purchased_customer,
                            'update_message' => $request->product_update_message
                        ]));
                    }
                }
            }

            return $product;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * getBestSellingProducts
     *
     * @param $request
     * @return void
     */

    public function getBestSellingProducts($request)
    {
        $limit = $request->limit ? $request->limit : 10;
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $range = !empty($request->range) && $request->range !== 'undefined'  ? $request->range : '';
        $type_id = $request->type_id ? $request->type_id : '';
        if (isset($request->type_slug) && empty($type_id)) {
            try {
                $type = Type::where('slug', $request->type_slug)->where('language', $language)->firstOrFail();
                $type_id = $type->id;
            } catch (ModelNotFoundException $e) {
                throw new MarvelException(NOT_FOUND);
            }
        }

        $products_query = Product::leftJoin('order_product', 'order_product.product_id', 'products.id')
            ->leftJoin('orders', 'order_product.order_id', '=', 'orders.id')
            // plantAttribute: the storefront card renders scientific name + fact
            // chips from it — without the eager-load this feed sent bare rows.
            ->with(['type', 'shop', 'plantAttribute'])
            ->selectRaw('products.*, sum(order_product.order_quantity) total_sales')
            ->where('orders.parent_id', null)
            ->where('orders.order_status', 'order-completed')
            ->where('orders.language', $language)
            ->groupBy('order_product.product_id')
            ->orderBy('total_sales', 'desc');

        if (isset($request->shop_id)) {
            $products_query = $products_query->where('shop_id', "=", $request->shop_id);
        }
        if ($range) {
            $products_query = $products_query->whereDate('created_at', '>', Carbon::now()->subDays($range));
        }
        if ($type_id) {
            $products_query = $products_query->where('type_id', '=', $type_id);
        }
        // City-first scope (same policy as the listing). Qualified id column for the joins.
        $city = $request->filled('city') ? (string) $request->city : null;
        $products_query = (new \Marvel\Services\AvailabilityService())->applyCityScope($products_query, $city, false, 'products.id');
        // Operations vertical filter, as the listing applies it (a coming-soon vertical never ranks).
        if (($verticals = app(\Marvel\Services\ServiceAvailabilityService::class)->verticalFilterForCity($city)) !== null) {
            $products_query->whereHas('type', fn ($t) => $t->whereIn('slug', $verticals));
        }
        return $products_query->take($limit)->get();
    }

    public function fetchRelated($slug, $limit = 10, $language = DEFAULT_LANGUAGE, $city = null)
    {
        try {
            $product    = $this->findOneByFieldOrFail('slug', $slug);
            $categories = $product->categories->pluck('id');
            // The product's own starting price: sale, else list, else the lowest size price.
            $anchor = (float) ($product->sale_price ?: $product->price ?: $product->min_price ?: 0);

            // Only products a shopper can buy right now (owner 2026-10-14: "Frequently bought
            // together" showed draft and test rows), from the same categories, nearest in price
            // first. A plain Product::query(), not $this->where(): the repository's request
            // criteria would eager-load the product page's seven relations onto every row.
            $query = Product::query()
                ->where('products.language', $language)
                ->where('products.id', '!=', $product->id)
                ->orderable()
                ->where('products.in_stock', true)
                ->whereHas('categories', function ($query) use ($categories) {
                    $query->whereIn('categories.id', $categories);
                })
                ->with('type')
                ->orderByRaw('ABS(COALESCE(NULLIF(products.min_price, 0), products.price, 0) - ?)', [$anchor])
                ->orderBy('products.id');
            // City-first scope and the Operations vertical filter (same policy as the listing):
            // a coming-soon vertical's products are never offered beside a plant.
            $query = (new \Marvel\Services\AvailabilityService())->applyCityScope($query, $city, false, 'products.id');
            if (($verticals = app(\Marvel\Services\ServiceAvailabilityService::class)->verticalFilterForCity($city)) !== null) {
                $query->whereHas('type', fn ($t) => $t->whereIn('slug', $verticals));
            }
            $related = $query->limit($limit)->get();

            // "Add all" must add a variable companion WITH a variant: give each its cheapest enabled,
            // priced one (null when it has none), all in one query. Price is a VARCHAR, so compare in PHP.
            $variable = $related->where('product_type', ProductType::VARIABLE);
            if ($variable->isNotEmpty()) {
                $price = fn ($v) => (float) ($v->sale_price ?: $v->price);
                $cheapest = Variation::whereIn('product_id', $variable->pluck('id'))
                    ->where('is_disable', false)
                    ->get(['id', 'product_id', 'title', 'price', 'sale_price', 'options'])
                    ->each->setAppends([]) // no blocked_dates query per row when serialized
                    ->filter(fn ($v) => $price($v) > 0)
                    ->sortBy('id')->sortBy($price) // stable: equal prices keep id order
                    ->unique('product_id') // the first, i.e. cheapest, row of each product
                    ->keyBy('product_id');
                foreach ($variable as $p) {
                    $p->setRelation('default_variation', $cheapest->get($p->id));
                }
            }

            return $related;
        } catch (Exception $e) {
            Log::warning('fetchRelated failed', ['slug' => $slug, 'error' => $e->getMessage()]);
            return [];
        }
    }

    public function getUnavailableProducts($from, $to)
    {
        $_blockedDates = Availability::whereDate('from', '<=', $from)
            ->whereDate('to', '>=', $to)
            ->get()->groupBy('product_id');

        $unavailableProducts = [];

        foreach ($_blockedDates as $productId =>  $date) {
            if (!$this->isProductAvailableAt($from, $to, $productId, $date)) {
                $unavailableProducts[] = $productId;
            }
        }
        return $unavailableProducts;
    }

    public function isProductAvailableAt($from, $to, $productId, $_blockedDates, $requestedQuantity = 1)
    {
        $quantity = 0;
        try {
            $product = Product::findOrFail($productId);
        } catch (\Throwable $th) {
            throw $th;
        }

        foreach ($_blockedDates as $singleDate) {
            $period = Period::make($singleDate['from'], $singleDate['to'], Precision::DAY, Boundaries::EXCLUDE_END);
            $range = Period::make($from, $to, Precision::DAY, Boundaries::EXCLUDE_END);
            if ($period->overlapsWith($range)) {
                $quantity += $singleDate->order_quantity;
            }
        }
        return $product->quantity - $quantity > $requestedQuantity;
    }


    public function fetchBlockedDatesForAProductInRange($from, $to, $productId)
    {
        return  Availability::where('product_id', $productId)->whereDate('from', '>=', $from)->whereDate('to', '<=', $to)->get();
    }

    public function fetchBlockedDatesForAVariationInRange($from, $to, $variation_id)
    {
        return  Availability::where('bookable_id', $variation_id)->where('bookable_type', 'Marvel\Database\Models\Variation')->whereDate('from', '>=', $from)->whereDate('to', '<=', $to)->get();
    }

    public function isVariationAvailableAt($from, $to, $variationId, $_blockedDates, $requestedQuantity)
    {
        $quantity = 0;
        try {
            $variation = Variation::findOrFail($variationId);
        } catch (\Throwable $th) {
            throw $th;
        }

        foreach ($_blockedDates as $singleDate) {
            $period = Period::make($singleDate['from'], $singleDate['to'], Precision::DAY, Boundaries::EXCLUDE_END);
            $range = Period::make($from, $to, Precision::DAY, Boundaries::EXCLUDE_END);
            if ($period->overlapsWith($range)) {
                $quantity += $singleDate->order_quantity;
            }
        }
        return $variation->quantity - $quantity >= $requestedQuantity;
    }


    public function calculatePrice($bookedDay, $product_id, $variation_id, $quantity, $persons, $dropoff_location_id, $pickup_location_id, $deposits, $features)
    {
        $price = 0;
        $person_price = 0;
        $deposit_price = 0;
        $feature_price = 0;
        $dropoff_location_price = 0;
        $pickup_location_price = 0;

        if ($variation_id) {
            $variation_price = $this->calculateVariationPrice($variation_id);
            $price += $variation_price * $bookedDay * $quantity;
        } else {
            $product_price = $this->calculateProductPrice($product_id);
            $price += $product_price * $bookedDay * $quantity;
        }
        if ($dropoff_location_id) {
            $dropoff_location_price = $this->calculateLocationPrice($dropoff_location_id);
        }
        if ($pickup_location_id) {
            $pickup_location_price = $this->calculateLocationPrice($pickup_location_id);
        }
        if ($features) {
            $feature_price = $this->calculateResourcePrice($features);
        }
        if ($persons) {
            $person_price = $this->calculateResourcePrice($persons);
        }
        if ($deposits) {
            $deposit_price = $this->calculateResourcePrice($deposits);
        }

        return [
            'totalPrice' => $price + $person_price + $deposit_price + $feature_price + $dropoff_location_price, $pickup_location_price,
            'personPrice' => $person_price,
            'depositPrice' => $deposit_price,
            'featurePrice' => $feature_price,
            'dropoffLocationPrice' => $dropoff_location_price,
            'pickupLocationPrice' => $pickup_location_price
        ];
    }

    public function calculateProductPrice($product_id)
    {
        try {
            $product = Product::findOrFail($product_id);
        } catch (\Throwable $th) {
            throw $th;
        }
        return $product->sale_price ? $product->sale_price : $product->price;
    }

    public function calculateVariationPrice($variation_id)
    {
        try {
            $variation = Variation::findOrFail($variation_id);
        } catch (\Throwable $th) {
            throw $th;
        }
        return $variation->sale_price ? $variation->sale_price : $variation->price;
    }

    public function calculateLocationPrice($location_id)
    {
        try {
            $location = Resource::findOrFail($location_id);
        } catch (\Throwable $th) {
            throw $th;
        }
        return $location->price;
    }

    public function calculateResourcePrice($resources)
    {
        $price = 0;
        foreach ($resources as $resource_id) {
            try {
                $resource = Resource::findOrFail($resource_id);
            } catch (\Throwable $th) {
                throw $th;
            }
            if ($resource->price) {
                $price += $resource->price;
            }
        }
        return $price;
    }

    public function customSlugify($text, string $divider = '-')
    {
        $slug      = preg_replace('~[^\pL\d]+~u', $divider, $text);
        $slugCount = Product::where('slug', $slug)->orWhere('slug', 'like',  $slug . '%')->count();

        if (empty($slugCount)) {
            return $slug;
        }

        return $slug . $divider . $slugCount;
    }
}
