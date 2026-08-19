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
        // Size chips filter through the variations axis (Small/Medium/Large);
        // in_stock backs the "in stock only" toggle — the storefronts used to
        // send both as PLAIN query params, which RequestCriteria ignores, so
        // those filters were silent no-ops until they moved into `search`.
        'variations.value' => 'in',
        'in_stock',
    ];

    /**
     * Boolean-backed searchable fields. Their values are coerced to 0/1 in
     * boot() before Prettus can compare them — under MySQL STRICT mode a
     * string vs tinyint comparison is a 500, and `is_rental` proved this class
     * of bug exists (it was explicitly filterable long before this list).
     */
    private const BOOLEAN_SEARCH_FIELDS = [
        'plantAttribute.pet_friendly',
        'in_stock',
        'is_rental',
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
        'shop_id',
        'sold_quantity',
        'visibility',
        'delivery_charge',
    ];
    public function getProductDataArray(): array
    {
        return $this->dataArray;
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
        // Nullable numeric columns: a blank string becomes null.
        foreach (['price', 'sale_price', 'min_price', 'max_price', 'quantity', 'sold_quantity'] as $col) {
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
            $data = $this->normalizeNumericFields($request->only($this->dataArray));
            $data['slug'] = $this->makeSlug($request);

            if ($setting->options["isProductReview"] ?? false) {
                // Master-catalog model: both admins and vendors may create a
                // product that goes live immediately, so PUBLISH is a valid
                // create status alongside draft/under_review.
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
                $product->variations()->attach($request['variations']);
            }
            if (isset($request['variation_options'])) {

                foreach ($request['variation_options']['upsert'] as $variation_option) {

                    if (isset($variation_option['is_digital']) && $variation_option['is_digital']) {
                        $file = $variation_option['digital_file'];
                        unset($variation_option['digital_file']);

                        unset($variation_option['inform_purchased_customer']);
                        unset($variation_option['product_update_message']);
                    }

                    $new_variation_option = $product->variation_options()->create($variation_option);

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
            if (empty($product->sku)) {
                $product->load(['type', 'categories', 'variation_options']);
                $skuGen = app(\Marvel\Services\SkuGenerator::class);
                $product->sku = $skuGen->forProduct($product);
                foreach ($product->variation_options as $i => $opt) {
                    if (empty($opt->sku)) {
                        $opt->sku = $skuGen->forVariation($product, $opt, $i);
                        $opt->saveQuietly();
                    }
                }
            }

            $product->save();

            // PlantAtHome: register any uploaded Featured/Gallery photos into the
            // plant photo library (product_images) on create. Plants only.
            if (optional($product->type)->slug === 'plants') {
                $product->reconcilePlantImages($data['image'] ?? null, $data['gallery'] ?? null);
                $this->syncPlantAttributeFromRequest($product, $request);
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
            'difficulty_level',
            'season',
            'life_cycle',
            'soil_type',
            'humidity',
            'fertilizer_requirement',
            'width_range',
            'care_guide',
            'planting_guide',
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

    public function checkProductForPublish($request, $product)
    {
        $status = '';
        // Master-catalog vendor: the product belongs to the master shop, so the
        // owner-id check below never matches a store owner. A vendor who created
        // this product (proposed_by_shop_id) controls its status directly —
        // honor the requested publish/unpublish/draft.
        $user = $request->user();
        if ($user && $product->proposed_by_shop_id && $user->hasPermissionTo(Permission::STORE_OWNER)) {
            $ownsProposal = \Marvel\Database\Models\Shop::where('owner_id', $user->id)
                ->where('id', (int) $product->proposed_by_shop_id)
                ->exists();
            if ($ownsProposal) {
                return in_array($request->status, [ProductStatus::PUBLISH, ProductStatus::UNPUBLISH, ProductStatus::DRAFT], true)
                    ? $request->status
                    : ProductStatus::PUBLISH;
            }
        }
        if ($product->shop['owner']['id'] == $request->user()->id) {
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
        } elseif ($request->user()->hasPermissionTo(Permission::SUPER_ADMIN)) {
            if ($request->status == ProductStatus::APPROVED) {
                $status = ProductStatus::PUBLISH;
                event(new ProductReviewApproved($product));
            } elseif ($request->status == ProductStatus::REJECTED) {
                $status = ProductStatus::REJECTED;
                event(new ProductReviewRejected($product));
            } elseif ($request->status == ProductStatus::PUBLISH) {
                return ProductStatus::PUBLISH;
            } elseif ($request->status == ProductStatus::UNPUBLISH) {
                $status = ProductStatus::UNPUBLISH;
            } else {
                $status = ProductStatus::REJECTED;
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
                $product->variations()->sync($request['variations']);
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
                if (isset($request['variation_options']['upsert'])) {
                    foreach ($request['variation_options']['upsert'] as $key => $variation) {

                        $variation['sale_price'] = isset($variation['sale_price']) ? $variation['sale_price'] : null;

                        if (isset($variation['is_digital']) && $variation['is_digital']) {

                            $file = $variation['digital_file'];
                            unset($variation['digital_file']);
                            unset($variation['inform_purchased_customer']);
                            unset($variation['product_update_message']);

                            if (isset($variation['id'])) {
                                $product->variation_options()->where('id', $variation['id'])->update($variation);

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
                                $new_variation = $product->variation_options()->create($variation);
                                $digital_file = $new_variation->digital_file()->create($file);
                                $new_variation->update([
                                    'digital_file_tracker' => $digital_file->id
                                ]);
                            }
                        } else {
                            if (isset($variation['id'])) {
                                $product->variation_options()->where('id', $variation['id'])->update($variation);
                            } else {
                                $product->variation_options()->create($variation);
                            }
                        }
                    }
                }
                if (isset($request['variation_options']['delete'])) {
                    foreach ($request['variation_options']['delete'] as $key => $id) {
                        try {
                            $product->variation_options()->where('id', $id)->delete();
                        } catch (Exception $e) {
                            //
                        }
                    }
                }
            }
            $data = $this->normalizeNumericFields($request->only($this->dataArray));
            $data['sale_price'] = isset($request['sale_price']) && $request['sale_price'] !== '' ? $request['sale_price'] : null;

            if ($setting->options["isProductReview"] ?? false) {
                $data['status'] = $this->checkProductForPublish($request, $product);
            }

            if ($request->product_type == ProductType::VARIABLE) {
                $data['price'] = NULL;
                $data['sale_price'] = NULL;
                $data['sku'] = NULL;
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

            $product->update($data);
            if ($product->product_type === ProductType::SIMPLE) {
                $product->variations()->delete();
                $product->variation_options()->delete();
            }
            $product->save();

            // PlantAtHome: keep the plant photo library (product_images) in sync with
            // the curated Featured/Gallery the editor submitted. Plants only.
            if (optional($product->type)->slug === 'plants') {
                $product->reconcilePlantImages($data['image'] ?? null, $data['gallery'] ?? null);
                $this->syncPlantAttributeFromRequest($product, $request);
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
                    foreach ($request['variation_options']['upsert'] as $variation_data) {
                        if ($variation_data['inform_purchased_customer']) {
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
            ->with(['type', 'shop'])
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
        return $products_query->take($limit)->get();
    }

    public function fetchRelated($slug, $limit = 10, $language = DEFAULT_LANGUAGE, $city = null)
    {
        try {
            $product    = $this->findOneByFieldOrFail('slug', $slug);
            $categories = $product->categories->pluck('id');

            $query = $this->where('language', $language)->whereHas('categories', function ($query) use ($categories) {
                $query->whereIn('categories.id', $categories);
            })->with('type');
            // City-first scope (same policy as the listing).
            $query = (new \Marvel\Services\AvailabilityService())->applyCityScope($query, $city, false, 'products.id');
            return $query->limit($limit)->get();
        } catch (Exception $e) {
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
