<?php

namespace Marvel\Http\Controllers;

use Exception;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Marvel\Database\Models\Type;
use Illuminate\Http\JsonResponse;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Wishlist;
use Marvel\Database\Models\Variation;
use Marvel\Exceptions\MarvelException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Author;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Manufacturer;
use Marvel\Http\Requests\ProductCreateRequest;
use Marvel\Http\Requests\ProductUpdateRequest;
use Marvel\Database\Repositories\ProductRepository;
use Marvel\Database\Repositories\SettingsRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Tag;
use Marvel\Exceptions\MarvelNotFoundException;
use \OpenAI;
use Marvel\Enums\Permission;
use Marvel\Http\Resources\GetSingleProductResource;
use Marvel\Http\Resources\ProductResource;
use Marvel\Traits\ApiResponseCache;

class ProductController extends CoreController
{
    use ApiResponseCache;

    public $repository;

    public $settings;

    public function __construct(ProductRepository $repository, SettingsRepository $settings)
    {
        $this->repository = $repository;
        $this->settings = $settings;
    }


    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Collection|Product[]
     */
    public function index(Request $request)
    {
        // SECURITY/perf: clamp the client-supplied page size on this PUBLIC endpoint so
        // ?limit=<huge> can't dump the full catalog (× eager relations) and bypass the
        // per-limit response cache.
        $limit = min(max((int) ($request->limit ?: 15), 1), 100);
        $language = $request->language ?: DEFAULT_LANGUAGE;

        // The storefront grids + filter sidebar hammer this endpoint. For
        // anonymous, non-time-sensitive reads serve a version-keyed server
        // cache AND let the Vercel edge cache the response. Admin (real
        // Bearer) and availability/flash-sale queries fall through to a fresh,
        // uncached response so the dashboard always sees current data.
        $cacheable = $this->isPublicCacheable($request)
            && !$request->filled('date_range')
            && !$request->boolean('flash_sale_builder');

        if (!$cacheable) {
            $products = $this->fetchProducts($request)->paginate($limit)->withQueryString();
            $data = ProductResource::collection($products)->response()->getData(true);
            return formatAPIResourcePaginate($data);
        }

        // Normalize the cache key: strip volatile analytics/cache-bust params and sort, so
        // UTM-tagged entry links and param reordering all hit ONE entry. Keep every
        // result-affecting param (search/searchFields/searchJoin/filter/orderBy/sortedBy/with/
        // price/city/availability) so functionally-different requests can't collide.
        $params = $request->query();
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', '_'] as $junk) {
            unset($params[$junk]);
        }
        $recursiveKsort = function (&$arr) use (&$recursiveKsort) {
            if (!is_array($arr)) {
                return;
            }
            ksort($arr);
            foreach ($arr as &$v) {
                if (is_array($v)) {
                    $recursiveKsort($v);
                }
            }
        };
        $recursiveKsort($params);
        $key = 'products:v' . $this->cacheVersion('products') . ':' . $language . ':' . md5(json_encode($params));
        $data = Cache::remember($key, 300, function () use ($request, $limit) {
            $products = $this->fetchProducts($request)->paginate($limit)->withQueryString();
            return ProductResource::collection($products)->response()->getData(true);
        });

        return formatAPIResourcePaginate($data)
            ->header('Cache-Control', $this->cacheControl());
    }



    /**
     * fetchProducts
     *
     * @param  mixed $request
     * @return object
     */
    public function fetchProducts(Request $request)
    {
        $unavailableProducts = [];
        $language = $request->language ? $request->language : DEFAULT_LANGUAGE;

        // PlantAtHome — eager-load botanical details + bundle items so the list
        // resource can expose scientific_name, care chips and bundle totals (no N+1).
        $products_query = $this->repository->with(['plantAttribute', 'bundleItems'])->where('language', $language);

        if (isset($request->date_range)) {
            $dateRange = explode('//', $request->date_range);
            $unavailableProducts = $this->repository->getUnavailableProducts($dateRange[0], $dateRange[1]);
        }
        if (in_array('variation_options.digital_files', explode(';', $request->with)) || in_array('digital_files', explode(';', $request->with))) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        $products_query = $products_query->whereNotIn('id', $unavailableProducts);

        // City-first availability (single source of truth: AvailabilityService::cityScopeProductIds):
        //   - city has vendor inventory -> STRICT, only that inventory
        //   - serviceable + unmapped    -> full master catalog (never empty a live city)
        //   - NOT serviceable           -> 0 products -> proper empty state (no cross-city leak)
        // `availability=local` narrows to same-city local delivery only.
        if ($request->filled('city')) {
            $localOnly = $request->input('availability') === 'local';
            $svc = new \Marvel\Services\AvailabilityService();
            $products_query = $svc->applyCityScope($products_query, (string) $request->city, $localOnly, 'products.id');
        }

        // Operations Control Center — hide products whose vertical is currently
        // unavailable. Applies to BOTH a GLOBAL disable (no city in the request)
        // and a PER-CITY disable (city present → that city's resolution). FAIL
        // OPEN: only narrows when a vertical is actually disabled, and never
        // empties the whole catalog (an all-off is the platform kill-switch).
        $availSvc = app(\Marvel\Services\ServiceAvailabilityService::class);
        $availCity = $request->filled('city') ? (string) $request->city : null;
        $availableVerticals = $availSvc->availableVerticalsForCity($availCity);
        $allVerticals = $availSvc->allVerticals();
        if (count($availableVerticals) > 0 && count($availableVerticals) < count($allVerticals)) {
            $products_query = $products_query->whereHas('type', function ($q) use ($availableVerticals) {
                $q->whereIn('slug', $availableVerticals);
            });
        }

        if ($request->flash_sale_builder) {
            $products_query = $this->repository->processFlashSaleProducts($request, $products_query);
        }

        return $products_query;
    }



    /**
     * Store a newly created resource in storage by rest.
     *
     * @param ProductCreateRequest $request
     * @return mixed
     */
    public function store(ProductCreateRequest $request)
    {
        return $this->ProductStore($request);
    }



    /**
     * Store a newly created resource in storage by GQL.
     *
     * @param Request $request
     * @return mixed
     */
    public function ProductStore(Request $request)
    {
        try {
            // inform_purchased_customer
            $setting = $this->settings->first();
            if ($this->repository->hasPermission($request->user(), $request->shop_id)) {
                $product = $this->repository->storeProduct($request, $setting);
                $this->bustResponseCache('products'); // refresh storefront list/PDP caches
                return $product;
            } else {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $e->getMessage());
        }
    }



    /**
     * Display the specified resource.
     *
     * @param $slug
     * @return JsonResponse
     */
    public function show(Request $request, $slug)
    {
        $request->merge(['slug' => $slug]);
        try {
            // Anonymous PDP reads that don't request gated digital files are edge-cacheable AND
            // now SERVER-cached: fetchSingleProduct otherwise looks the slug up twice + loads 5
            // relations on every origin/ISR-revalidate hit. The per-user fields (in_wishlist,
            // my_review) are null when anonymous, so the serialized payload is invariant —
            // language+slug+limit is a complete key. Reuses the 'products' invalidation namespace.
            $withDigital = in_array('variation_options.digital_file', explode(';', (string) $request->with))
                || in_array('digital_file', explode(';', (string) $request->with));
            $cacheable = $this->isPublicCacheable($request) && !$withDigital;

            if (!$cacheable) {
                $product = $this->fetchSingleProduct($request);
                $data = (new GetSingleProductResource($product))->response()->getData(true);
                $data = $this->attachAvailability($data, $request);
                return response()->json($data);
            }

            $language = $request->language ?? DEFAULT_LANGUAGE;
            $limit = isset($request->limit) ? $request->limit : 10;
            $key = 'product:show:v' . $this->cacheVersion('products') . ':' . $language . ':' . $slug . ':' . $limit;
            $data = Cache::remember($key, 300, function () use ($request) {
                return (new GetSingleProductResource($this->fetchSingleProduct($request)))->response()->getData(true);
            });
            // Operations Control Center — availability depends on the request's
            // city (not in the cache key), so it's resolved + attached per-request.
            $data = $this->attachAvailability($data, $request);
            return response()->json($data)->header('Cache-Control', $this->cacheControl());
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Operations Control Center — attach an `availability` block to a PDP
     * response for the request's city. Fail open (city absent / no vertical /
     * error ⇒ no block, never throws). The storefront reads it to gate
     * add-to-cart + show the maintenance message.
     */
    private function attachAvailability(array $data, Request $request): array
    {
        try {
            if (!$request->filled('city')) {
                return $data;
            }
            $slug = \Illuminate\Support\Arr::get($data, 'data.type.slug');
            if (!$slug) {
                $pslug = \Illuminate\Support\Arr::get($data, 'data.slug') ?? $request->input('slug');
                $typeId = \Marvel\Database\Models\Product::where('slug', $pslug)->value('type_id');
                $slug = $typeId ? \Marvel\Database\Models\Type::where('id', $typeId)->value('slug') : null;
            }
            if (!$slug) {
                return $data;
            }
            $av = app(\Marvel\Services\ServiceAvailabilityService::class)->resolve($slug, (string) $request->city);
            \Illuminate\Support\Arr::set($data, 'data.availability', $av);
        } catch (\Throwable $e) {
            // fail open
        }
        return $data;
    }



    /**
     * Display the specified resource.
     *
     * @param $slug
     * @return JsonResponse
     */
    public function fetchSingleProduct(Request $request)
    {
        try {
            $slug = $request->slug;
            $language = $request->language ?? DEFAULT_LANGUAGE;
            $user = $request->user();
            $limit = isset($request->limit) ? $request->limit : 10;
            $product = $this->repository->where('language', $language)->where('slug', $slug)->orWhere('id', $slug)->firstOrFail();
            if (
                in_array('variation_options.digital_file', explode(';', $request->with)) || in_array('digital_file', explode(';', $request->with))
            ) {
                if (!$this->repository->hasPermission($user, $product->shop_id)) {
                    throw new AuthorizationException(NOT_AUTHORIZED);
                }
            }
            $related_products = $this->repository->fetchRelated($slug, $limit, $language);
            $product->setRelation('related_products', $related_products);

            // PlantAtHome: eager-load botanical details + ordered gallery images
            // + bundle items + buy-together add-ons + the shop (needed for review shop_id).
            $product->load(['plantAttribute', 'images', 'bundleItems', 'addons', 'shop']);

            return $product;
        } catch (Exception $e) {
            throw new MarvelNotFoundException();
        }
    }


    /**
     * Update the specified resource in storage.
     *
     * @param ProductUpdateRequest $request
     * @param int $id
     * @return array
     */
    public function update(ProductUpdateRequest $request, $id)
    {
        try {
            $request->id = $id;
            return $this->updateProduct($request);
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }


    /**
     * updateProduct
     *
     * @param  Request $request
     * @return array
     */
    public function updateProduct(Request $request)
    {
        $setting = $this->settings->first();
        if ($this->repository->hasPermission($request->user(), $request->shop_id)) {
            $id = $request->id;
            $product = $this->repository->updateProduct($request, $id, $setting);
            $this->bustResponseCache('products'); // refresh storefront list/PDP caches (incl. bundle/add-on edits)
            return $product;
        } else {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
    }


    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     * @return JsonResponse
     */
    public function destroy(Request $request, $id)
    {
        $request->id = $id;
        return $this->destroyProduct($request);
    }


    /**
     * destroyProduct
     *
     * @param  Request $request
     * @return void
     */
    public function destroyProduct(Request $request)
    {
        try {
            $product = $this->repository->findOrFail($request->id);
            if ($this->repository->hasPermission($request->user(), $product->shop_id)) {
                $product->delete();
                $this->bustResponseCache('products'); // refresh storefront list/PDP caches
                return $product;
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $e) {
            throw new MarvelException($e->getMessage());
        }
    }



    /**
     * relatedProducts
     *
     * @param  Request $request
     * @return void
     */
    public function relatedProducts(Request $request)
    {
        $limit = isset($request->limit) ? $request->limit : 10;
        $slug =  $request->slug;
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $city = $request->filled('city') ? (string) $request->city : null;
        // Related = whereHas('categories') join per PDP render; cache anonymous reads.
        if (!$this->isPublicCacheable($request)) {
            return $this->repository->fetchRelated($slug, $limit, $language, $city);
        }
        $key = 'products:related:v' . $this->cacheVersion('products') . ':' . $language . ':' . $slug . ':' . $limit . ':' . strtolower((string) $city);
        return response(Cache::remember($key, 300, fn () => $this->repository->fetchRelated($slug, $limit, $language, $city)))
            ->header('Cache-Control', $this->cacheControl());
    }



    /**
     * exportProducts
     *
     * @param  Request $request
     * @param  mixed $shop_id
     * @return void
     */
    public function exportProducts(Request $request, $shop_id)
    {

        $filename = 'products-for-shop-id-' . $shop_id . '.csv';
        $headers = [
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Content-type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename=' . $filename,
            'Expires'             => '0',
            'Pragma'              => 'public'
        ];

        $list = $this->repository->with([
            'categories',
            'tags',
        ])->where('shop_id', $shop_id)->get()->toArray();

        if (!count($list)) {
            return response()->stream(function () {
                //
            }, 200, $headers);
        }
        # add headers for each column in the CSV download
        array_unshift($list, array_keys($list[0]));

        $callback = function () use ($list) {
            $FH = fopen('php://output', 'w');
            foreach ($list as $key => $row) {
                if ($key === 0) {
                    $exclude = ['id', 'slug', 'deleted_at', 'created_at', 'updated_at', 'shipping_class_id', 'ratings', 'total_reviews', 'my_review', 'in_wishlist', 'rating_count', 'translated_languages'];
                    $row = array_diff($row, $exclude);
                }
                unset($row['id']);
                unset($row['deleted_at']);
                unset($row['shipping_class_id']);
                unset($row['updated_at']);
                unset($row['created_at']);
                unset($row['slug']);
                unset($row['ratings']);
                unset($row['total_reviews']);
                unset($row['my_review']);
                unset($row['in_wishlist']);
                unset($row['rating_count']);
                unset($row['translated_languages']);
                if (isset($row['image'])) {
                    $row['image'] = json_encode($row['image']);
                }
                if (isset($row['gallery'])) {
                    $row['gallery'] = json_encode($row['gallery']);
                }
                if (isset($row['blocked_dates'])) {
                    $row['blocked_dates'] = json_encode($row['blocked_dates']);
                }
                if (isset($row['video'])) {
                    $row['video'] = json_encode($row['video']);
                }
                if (isset($row['categories'])) {
                    $categories = collect($row['categories'])->pluck('id')->toArray();
                    $row['categories'] = json_encode($categories);
                }
                if (isset($row['tags'])) {
                    $tagIds = collect($row['tags'])->pluck('pivot.tag_id')->toArray();
                    $row['tags'] = json_encode($tagIds);
                }
                fputcsv($FH, $row);
            }
            fclose($FH);
        };

        return response()->stream($callback, 200, $headers);
    }



    /**
     * exportVariableOptions
     *
     * @param  Request $request
     * @param  mixed $shop_id
     * @return void
     */
    public function exportVariableOptions(Request $request, $shop_id)
    {
        $filename = 'variable-options-' . Str::random(5) . '.csv';
        $headers = [
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Content-type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename=' . $filename,
            'Expires'             => '0',
            'Pragma'              => 'public'
        ];

        $products = $this->repository->where('shop_id', $shop_id)->get();

        $list = Variation::WhereIn('product_id', $products->pluck('id'))->get()->toArray();

        if (!count($list)) {
            return response()->stream(function () {
                //
            }, 200, $headers);
        }
        # add headers for each column in the CSV download
        array_unshift($list, array_keys($list[0]));

        $callback = function () use ($list) {
            $FH = fopen('php://output', 'w');
            foreach ($list as $key => $row) {
                if ($key === 0) {
                    $exclude = ['id', 'created_at', 'updated_at', 'translated_languages'];
                    $row = array_diff($row, $exclude);
                }
                unset($row['id']);
                unset($row['updated_at']);
                unset($row['created_at']);
                unset($row['translated_languages']);
                if (isset($row['options'])) {
                    $row['options'] = json_encode($row['options']);
                }
                if (isset($row['blocked_dates'])) {
                    $row['blocked_dates'] = json_encode($row['blocked_dates']);
                }
                fputcsv($FH, $row);
            }
            fclose($FH);
        };

        return response()->stream($callback, 200, $headers);
    }




    /**
     * importProducts
     *
     * @param  Request $request
     * @return bool
     */
    public function importProducts(Request $request)
    {
        $requestFile = $request->file();
        $user = $request->user();
        $shop_id = $request->shop_id;

        if (count($requestFile)) {
            if (isset($requestFile['csv'])) {
                $uploadedCsv = $requestFile['csv'];
            } else {
                $uploadedCsv = current($requestFile);
            }
        }

        if (!$this->repository->hasPermission($user, $shop_id)) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        if (isset($shop_id)) {
            $file = $uploadedCsv->storePubliclyAs('csv-files', 'products-' . $shop_id . '.' . $uploadedCsv->getClientOriginalExtension(), 'public');

            $products = $this->repository->csvToArray(storage_path() . '/app/public/' . $file);

            foreach ($products as $key => $product) {
                if (!isset($product['type_id'])) {
                    throw new MarvelException("MARVEL_ERROR.WRONG_CSV");
                }
                unset($product['id']);
                $product['shop_id'] = $shop_id;
                $product['image'] = json_decode($product['image'], true);
                $product['gallery'] = json_decode($product['gallery'], true);
                $product['video'] = json_decode($product['video'], true);
                $categoriesId = json_decode($product['categories'], true);
                $tagsId = json_decode($product['tags'], true);
                try {
                    $type = Type::findOrFail($product['type_id']);
                    $authorCacheKey = $product['author_id'] . '_author_id';
                    $manufacturerCacheKey = $product['manufacturer_id'] . '_manufacturer_id';
                    $product['author_id'] = Cache::remember($authorCacheKey, 30, fn () => Author::find($product['author_id'])?->id);
                    $product['manufacturer_id'] = Cache::remember($manufacturerCacheKey, 30, fn () => Manufacturer::find($product['manufacturer_id'])?->id);
                    $dataArray = $this->repository->getProductDataArray();
                    $productArray = array_intersect_key($product, array_flip($dataArray));
                    if (isset($type->id)) {
                        $newProduct = Product::FirstOrCreate($productArray);
                        $categoryCacheKey = $product['categories'] . '_categories';
                        $tagCacheKey = $product['tags'] . '_tags';
                        $categories = Cache::remember($categoryCacheKey, 30, fn () => Category::whereIn('id', $categoriesId)->get());
                        $tags = Cache::remember($tagCacheKey, 30, fn () => Tag::whereIn('id', $tagsId)->get());
                        if (!empty($categories)) {
                            $newProduct->categories()->attach($categories);
                        }
                        if (!empty($tags)) {
                            $newProduct->tags()->attach($tags);
                        }
                    }
                } catch (Exception $e) {
                    //
                }
            }
            return true;
        }
    }



    /**
     * importVariationOptions
     *
     * @param  Request $request
     * @return bool
     */
    public function importVariationOptions(Request $request)
    {
        $requestFile = $request->file();
        $user = $request->user();
        $shop_id = $request->shop_id;

        if (count($requestFile)) {
            if (isset($requestFile['csv'])) {
                $uploadedCsv = $requestFile['csv'];
            } else {
                $uploadedCsv = current($requestFile);
            }
        } else {
            throw new MarvelException(CSV_NOT_FOUND);
        }

        if (!$this->repository->hasPermission($user, $shop_id)) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        if (isset($user->id)) {
            $file = $uploadedCsv->storePubliclyAs('csv-files', 'variation-options-' . Str::random(5) . '.' . $uploadedCsv->getClientOriginalExtension(), 'public');

            $attributes = $this->repository->csvToArray(storage_path() . '/app/public/' . $file);

            foreach ($attributes as $key => $attribute) {
                if (!isset($attribute['title']) || !isset($attribute['price'])) {
                    throw new MarvelException("MARVEL_ERROR.WRONG_CSV");
                }
                unset($attribute['id']);
                $attribute['options'] = json_decode($attribute['options'], true);
                try {
                    $product = Type::findOrFail($attribute['product_id']);
                    if (isset($product->id)) {
                        Variation::firstOrCreate($attribute);
                    }
                } catch (Exception $e) {
                    //
                }
            }
            return true;
        }
    }



    /**
     * fetchDigitalFilesForProduct
     *
     * @param  Request $request
     * @return void
     */
    public function fetchDigitalFilesForProduct(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $product = $this->repository->with(['digital_file'])->findOrFail($request->parent_id);
            if ($this->repository->hasPermission($user, $product->shop_id)) {
                return $product->digital_file;
            }
        }
    }



    /**
     * fetchDigitalFilesForVariation
     *
     * @param  Request $request
     * @return void
     */
    public function fetchDigitalFilesForVariation(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $variation_option = Variation::with(['digital_file', 'product'])->findOrFail($request->parent_id);
            if ($this->repository->hasPermission($user, $variation_option->product->shop_id)) {
                return $variation_option->digital_file;
            }
        }
    }



    /**
     * bestSellingProducts
     *
     * @param  Request $request
     * @return void
     */

    public function bestSellingProducts(Request $request)
    {
        // Heaviest homepage feed (leftJoin order_product + orders + sum + groupBy + sort).
        // Cache anonymous reads under the 'products' namespace.
        if (!$this->isPublicCacheable($request)) {
            return $this->repository->getBestSellingProducts($request);
        }
        $limit = $request->limit ? $request->limit : 10;
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $key = 'products:bestselling:v' . $this->cacheVersion('products') . ':' . $language . ':'
            . ($request->type_id ?? '') . ':' . ($request->type_slug ?? '') . ':' . ($request->range ?? '') . ':' . $limit
            . ':' . strtolower((string) ($request->filled('city') ? $request->city : ''));
        return response(Cache::remember($key, 300, fn () => $this->repository->getBestSellingProducts($request)))
            ->header('Cache-Control', $this->cacheControl());
    }



    /**
     * popularProducts
     *
     * @param  Request $request
     * @return object
     */
    /**
     * F2 — bundles report DERIVED stock (MIN over components), not the snapshot
     * products.quantity that drifts as components sell. Applied to the popular /
     * top-rated / drafted / low-stock feeds, which return RAW products (not via
     * ProductResource). Surgical: only a bundle's `quantity` is overridden — every
     * other field and the response shape are untouched, so non-bundles (and the
     * storefront/admin lists that consume these) are unaffected.
     */
    private function withDerivedBundleStock($products)
    {
        $apply = function ($p) {
            if (($p->product_type ?? null) === \Marvel\Enums\ProductType::BUNDLE) {
                $p->quantity = (int) $p->available_bundle_inventory;
            }
            return $p;
        };
        if ($products instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) {
            $products->getCollection()->transform($apply);
        } elseif ($products instanceof \Illuminate\Support\Collection) {
            $products->transform($apply);
        }
        return $products;
    }

    public function popularProducts(Request $request)
    {
        $limit = $request->limit ? $request->limit : 10;
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $range = !empty($request->range) && $request->range !== 'undefined'  ? $request->range : '';
        $type_id = $request->type_id ? $request->type_id : '';
        if (isset($request->type_slug) && empty($type_id)) {
            try {
                $type = Type::where('slug', $request->type_slug)->where('language', $language)->firstOrFail();
                $type_id = $type->id;
            } catch (MarvelException $e) {
                throw new MarvelException(NOT_FOUND);
            }
        }
        // Public homepage feed: full-catalog withCount('orders') + sort aggregate, run on every
        // anonymous SSR render. Serve a version-keyed server cache (+ edge header) for anonymous
        // reads; admin/Bearer reads fall through fresh. Reuses the 'products' namespace, so the
        // existing bustResponseCache('products') on any product write already invalidates it.
        $city = $request->filled('city') ? (string) $request->city : null;
        $build = function () use ($request, $limit, $language, $range, $type_id, $city) {
            $products_query = $this->repository->withCount('orders')->with(['type', 'shop'])->orderBy('orders_count', 'desc')->where('language', $language);
            if (isset($request->shop_id)) {
                $products_query = $products_query->where('shop_id', "=", $request->shop_id);
            }
            if ($range) {
                $products_query = $products_query->whereDate('created_at', '>', Carbon::now()->subDays($range));
            }
            if ($type_id) {
                $products_query = $products_query->where('type_id', '=', $type_id);
            }
            // City-first scope (same policy as the listing).
            $products_query = (new \Marvel\Services\AvailabilityService())->applyCityScope($products_query, $city, false, 'products.id');
            return $this->withDerivedBundleStock($products_query->take($limit)->get());
        };
        if (!$this->isPublicCacheable($request)) {
            return $build();
        }
        $key = 'products:popular:v' . $this->cacheVersion('products') . ':' . $language . ':' . $type_id . ':' . $limit . ':' . $range . ':' . ($request->shop_id ?? '') . ':' . strtolower((string) $city);
        return response(Cache::remember($key, 300, $build))->header('Cache-Control', $this->cacheControl());
    }

    /**
     * Public top-rated products — highest average review rating first (only products that
     * have at least one review). Mirrors popularProducts()'s params (type_slug/limit/shop_id).
     */
    public function topRatedProducts(Request $request)
    {
        $limit = $request->limit ? $request->limit : 10;
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $type_id = $request->type_id ? $request->type_id : '';
        if (isset($request->type_slug) && empty($type_id)) {
            try {
                $type = Type::where('slug', $request->type_slug)->where('language', $language)->firstOrFail();
                $type_id = $type->id;
            } catch (MarvelException $e) {
                throw new MarvelException(NOT_FOUND);
            }
        }
        // Public homepage feed (withAvg('reviews') + whereHas join aggregate); cache anonymous
        // reads under the 'products' namespace, mirroring popularProducts.
        $city = $request->filled('city') ? (string) $request->city : null;
        $build = function () use ($request, $limit, $language, $type_id, $city) {
            $products_query = $this->repository
                ->with(['type', 'shop'])
                ->withAvg('reviews', 'rating')
                ->whereHas('reviews')
                ->where('language', $language)
                ->orderByDesc('reviews_avg_rating');
            if (isset($request->shop_id)) {
                $products_query = $products_query->where('shop_id', '=', $request->shop_id);
            }
            if ($type_id) {
                $products_query = $products_query->where('type_id', '=', $type_id);
            }
            $products_query = (new \Marvel\Services\AvailabilityService())->applyCityScope($products_query, $city, false, 'products.id');
            return $this->withDerivedBundleStock($products_query->take($limit)->get());
        };
        if (!$this->isPublicCacheable($request)) {
            return $build();
        }
        $key = 'products:toprated:v' . $this->cacheVersion('products') . ':' . $language . ':' . $type_id . ':' . $limit . ':' . ($request->shop_id ?? '') . ':' . strtolower((string) $city);
        return response(Cache::remember($key, 300, $build))->header('Cache-Control', $this->cacheControl());
    }



    /**
     * calculateRentalPrice
     *
     * @param  Request $request
     * @return void
     */
    public function calculateRentalPrice(Request $request)
    {
        $isAvailable = true;
        $product_id = $request->product_id;
        try {
            $product = Product::findOrFail($product_id);
        } catch (MarvelException $th) {
            throw new MarvelException(NOT_FOUND);
        }
        if (!$product->is_rental) {
            throw new MarvelException(NOT_A_RENTAL_PRODUCT);
        }
        $variation_id = $request->variation_id;
        $quantity = $request->quantity;
        $persons = $request->persons;
        $dropoff_location_id = $request->dropoff_location_id;
        $pickup_location_id = $request->pickup_location_id;
        $deposits = $request->deposits;
        $features = $request->features;
        $from = $request->from;
        $to = $request->to;
        if ($variation_id) {
            $blockedDates = $this->repository->fetchBlockedDatesForAVariationInRange($from, $to, $variation_id);
            $isAvailable = $this->repository->isVariationAvailableAt($from, $to, $variation_id, $blockedDates, $quantity);
            if (!$isAvailable) {
                throw new marvelException(NOT_AVAILABLE_FOR_BOOKING);
            }
        } else {
            $blockedDates = $this->repository->fetchBlockedDatesForAProductInRange($from, $to, $product_id);
            $isAvailable = $this->repository->isProductAvailableAt($from, $to, $product_id, $blockedDates, $quantity);
            if (!$isAvailable) {
                throw new marvelException(NOT_AVAILABLE_FOR_BOOKING);
            }
        }

        $from = Carbon::parse($from);
        $to = Carbon::parse($to);

        $bookedDay = $from->diffInDays($to);

        return $this->repository->calculatePrice($bookedDay, $product_id, $variation_id, $quantity, $persons, $dropoff_location_id, $pickup_location_id, $deposits, $features);
    }



    /**
     * myWishlists
     *
     * @param  Request $request
     * @return void
     */
    public function myWishlists(Request $request)
    {
        $limit = $request->limit ? $request->limit : 10;
        return $this->fetchWishlists($request)->paginate($limit);
    }



    /**
     * fetchWishlists
     *
     * @param  Request $request
     * @return object
     */
    public function fetchWishlists(Request $request)
    {
        $user = $request->user();
        $wishlist = Wishlist::where('user_id', $user->id)->pluck('product_id');
        return $this->repository->whereIn('id', $wishlist);
    }


    /**
     * draftedProducts
     *
     * @param  Request $request
     * @return void
     */
    public function draftedProducts(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;

        return $this->withDerivedBundleStock($this->fetchDraftedProducts($request)->paginate($limit));
    }

    /**
     * fetchDraftedProducts
     *
     * @param  Request $request
     * @return mixed
     */
    public function fetchDraftedProducts(Request $request)
    {
        $user = $request->user() ?? null;;
        $language = $request->language ? $request->language : DEFAULT_LANGUAGE;

        $products_query = $this->repository->with(['type', 'shop'])->where('language', $language);

        switch ($user) {
            case $user->hasPermissionTo(Permission::SUPER_ADMIN):
                return $products_query->whereIn('shop_id', $user->shops->pluck('id'));
                break;

            case $user->hasPermissionTo(Permission::STORE_OWNER):
                if (isset($request->shop_id)) {
                    return $products_query->where('shop_id', '=', $request->shop_id);
                } else {
                    return $products_query->whereIn('shop_id', $user->shops->pluck('id'));
                }
                break;

            case $user->hasPermissionTo(Permission::STAFF):
                if (isset($request->shop_id)) {
                    return $products_query->where('shop_id', '=', $request->shop_id);
                } else {
                    return $products_query->where('shop_id', $user->managed_shop->id);
                }
                break;
        }

        return $products_query;
    }

    /**
     * productStock
     *
     * @param  Request $request
     * @return void
     */
    public function productStock(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;

        return $this->withDerivedBundleStock($this->fetchProductStock($request)->paginate($limit));
    }

    /**
     * productStock
     *
     * @param  Request $request
     * @return mixed
     */
    public function fetchProductStock(Request $request)
    {
        $user = $request->user();
        $language = $request->language ? $request->language : DEFAULT_LANGUAGE;

        $products_query = $this->repository->with(['type', 'shop'])->where('language', $language)->where('quantity', '<', 10);

        switch ($user) {
            case $user->hasPermissionTo(Permission::SUPER_ADMIN):
                if (isset($request->shop_id)) {
                    return $products_query->where('shop_id', '=', $request->shop_id);
                } else {
                    return $products_query;
                }
                break;

            case $user->hasPermissionTo(Permission::STORE_OWNER):
                if (isset($request->shop_id)) {
                    // shop specific
                    return $products_query->where('shop_id', '=', $request->shop_id);
                } else {
                    // overall shops
                    return $products_query->whereIn('shop_id', $user->shops->pluck('id'));
                }
                break;

            case $user->hasPermissionTo(Permission::STAFF):
                if (isset($request->shop_id)) {
                    return $products_query->where('shop_id', '=', $request->shop_id);
                } else {
                    return $products_query->where('shop_id', '=', null);
                }
                break;

            default:
                return $products_query->where('shop_id', '=', null);

                break;
        }

        return $products_query;
    }
}
