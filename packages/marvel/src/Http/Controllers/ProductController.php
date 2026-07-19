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
            $this->overlayCityPrices($products, $request->filled('city') ? (string) $request->city : null);
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
            $this->overlayCityPrices($products, $request->filled('city') ? (string) $request->city : null);
            return ProductResource::collection($products)->response()->getData(true);
        });

        return formatAPIResourcePaginate($data)
            ->header('Cache-Control', $this->cacheControl());
    }

    /**
     * City-price overlay for listings: when the customer's city is known and a
     * product has vendor inventory there, the card price becomes the projected CITY
     * selling price (MAX vendor rate + PlantAtHome margin — product_city_availability
     * .min_price, maintained by AvailabilityService). Cards then match the PDP's
     * location-price and the checkout-verified charge. `city` is part of the response
     * cache key, so cached entries stay city-correct. sale_price is cleared so the
     * overlay never renders as a fake strike-through discount.
     */
    private function overlayCityPrices($products, ?string $city): void
    {
        if (empty($city)) {
            return;
        }
        try {
            $svc = new \Marvel\Services\AvailabilityService();
            $key = $svc->normalizeCityKey($city);
            if ($key === '') {
                return;
            }
            $items = collect($products->items());
            $ids = $items->pluck('id')->filter()->all();
            if (empty($ids)) {
                return;
            }
            $cityPrices = \Marvel\Database\Models\ProductCityAvailability::whereIn('product_id', $ids)
                ->where('city', $key)
                ->whereNotNull('min_price')
                ->pluck('min_price', 'product_id');
            if ($cityPrices->isEmpty()) {
                return;
            }
            foreach ($items as $p) {
                $cp = $cityPrices[$p->id] ?? null;
                if ($cp === null || (float) $cp <= 0) {
                    continue;
                }
                $p->price = (float) $cp;
                $p->sale_price = null;
                if ((float) ($p->min_price ?? 0) > 0) {
                    $p->min_price = (float) $cp; // variable products: "from ₹" = city price
                }
            }
        } catch (\Throwable $e) {
            // overlay is best-effort — never break the listing
        }
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
        // `type` + `shop` are read by ProductResource (getResourceData) per row —
        // eager-load them too, else the listing fires 2 extra queries per product
        // (measured 68 queries for 20 rows → ~10 with these included).
        $products_query = $this->repository->with(['type', 'shop', 'plantAttribute', 'bundleItems'])->where('language', DEFAULT_LANGUAGE);

        if (isset($request->date_range)) {
            $dateRange = explode('//', $request->date_range);
            $unavailableProducts = $this->repository->getUnavailableProducts($dateRange[0], $dateRange[1]);
        }
        if (in_array('variation_options.digital_files', explode(';', $request->with)) || in_array('digital_files', explode(';', $request->with))) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        $products_query = $products_query->whereNotIn('id', $unavailableProducts);

        // Customer surfaces (shop listings/search) pass hide_unpriced=1 so
        // unpriced catalogue entries (imported names approved at ₹0, awaiting
        // vendor rates) never render as buyable "₹0.00" cards. Opt-in param —
        // admin/vendor tooling is unaffected.
        if ($request->boolean('hide_unpriced')) {
            $products_query = $products_query->where(function ($q) {
                $q->where('products.price', '>', 0)
                    ->orWhere('products.sale_price', '>', 0)
                    ->orWhere('products.max_price', '>', 0);
            });
        }

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
        // ── Single-shop master catalog ─────────────────────────────────────────
        // Every product belongs to THE master PlantAtHome shop. Both a super admin
        // and a store owner (vendor) create directly into the master catalog and
        // the product goes LIVE immediately (no review gate). A vendor create is
        // still attributed to their shop via proposed_by_shop_id so they retain
        // edit/delete rights over their own products.
        $user = $request->user();
        $isSuperAdmin = $user && $user->hasPermissionTo(Permission::SUPER_ADMIN);
        $vendorShopId = null;
        if (!$isSuperAdmin) {
            $vendorShopId = $this->vendorActiveShopId($user);
            if (!$vendorShopId) {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }
        }

        // The master catalog stays UNIQUE: an exact (case-insensitive) name match
        // within the same vertical blocks the create and points at the existing
        // product, so the vendor attaches inventory to it instead of duplicating.
        $existing = $this->findCatalogDuplicate((string) $request->name, $request->type_id ? (int) $request->type_id : null);
        if ($existing) {
            return response()->json([
                'message'  => 'This product is already in the PlantAtHome catalogue — attach your rate & stock to it from "Add from catalogue" instead of creating a duplicate.',
                'existing' => [
                    'id'     => $existing->id,
                    'name'   => $existing->name,
                    'slug'   => $existing->slug,
                    'status' => $existing->status,
                ],
            ], 422);
        }

        $request->merge(['shop_id' => \Marvel\Database\Models\Shop::masterId()]);
        // Vendors publish straight to the live catalog like admins. If the form
        // didn't send a status, default a vendor create to PUBLISH (go live);
        // an explicit status (e.g. Draft) is honored.
        if (!$isSuperAdmin && !$request->filled('status')) {
            $request->merge(['status' => \Marvel\Enums\ProductStatus::PUBLISH]);
        }

        try {
            // inform_purchased_customer
            $setting = $this->settings->first();
            $product = $this->repository->storeProduct($request, $setting);
            // Attribute a vendor proposal to their shop (review-queue display + the
            // vendor's own edit rights while pending). Guarded for deploy lag.
            if ($vendorShopId && $product && \Illuminate\Support\Facades\Schema::hasColumn('products', 'proposed_by_shop_id')) {
                $product->proposed_by_shop_id = $vendorShopId;
                $product->save();
            }
            $this->bustResponseCache('products'); // refresh storefront list/PDP caches
            return $product;
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $e->getMessage());
        }
    }

    /** The caller's ACTIVE shop id (store owner), or null. Approval gates proposing. */
    private function vendorActiveShopId($user): ?int
    {
        if (!$user || !$user->hasPermissionTo(Permission::STORE_OWNER)) {
            return null;
        }
        $id = \Marvel\Database\Models\Shop::where('owner_id', $user->id)
            ->where('is_active', true)
            ->where('slug', '!=', \Marvel\Database\Models\Shop::MASTER_SLUG)
            ->value('id');
        return $id ? (int) $id : null;
    }

    /** Exact (case-insensitive) catalog duplicate by name within a vertical, if any. */
    private function findCatalogDuplicate(string $name, ?int $typeId): ?Product
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        return Product::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->where('language', DEFAULT_LANGUAGE)
            ->when($typeId, fn ($q) => $q->where('type_id', $typeId))
            ->first();
    }

    /**
     * Fuzzy near-duplicate detection for the propose flow. Beyond the exact match,
     * find catalog products whose name is SIMILAR (shared first token or a
     * scientific-name match), scored by PHP similar_text, so a vendor is warned
     * before proposing "Money Plant" when "Money-Plant" already exists. Returns the
     * top matches ≥ threshold. Read-only; safe to call as a pre-submit check.
     *
     * @return array<int, array{id:int,name:string,slug:string,status:string,score:float}>
     */
    private function similarProducts(string $name, ?int $typeId, float $threshold = 55.0, int $limit = 6): array
    {
        $name = trim($name);
        if (mb_strlen($name) < 3) {
            return [];
        }
        // First meaningful token, splitting on spaces AND hyphens/underscores so
        // "Money-Plant" and "Money Plant" both key on "Money".
        $tokens = array_values(array_filter(preg_split('/[\s\-_]+/', $name), fn ($t) => mb_strlen($t) >= 3));
        $firstToken = $tokens[0] ?? $name;

        $candidates = Product::query()
            ->where('language', DEFAULT_LANGUAGE)
            ->when($typeId, fn ($q) => $q->where('type_id', $typeId))
            ->where(function ($q) use ($firstToken, $name) {
                $q->where('name', 'like', $firstToken . '%')
                    ->orWhere('name', 'like', '%' . $firstToken . '%')
                    ->orWhere('name', 'like', '%' . $name . '%');
            })
            ->limit(60)
            ->get(['id', 'name', 'slug', 'status']);

        // scientific-name match (strong signal) from plant_attributes.
        $scientific = \Marvel\Database\Models\PlantAttribute::whereRaw('LOWER(scientific_name) = ?', [mb_strtolower($name)])
            ->pluck('product_id')->all();

        $lname = mb_strtolower($name);
        $scored = [];
        foreach ($candidates as $p) {
            similar_text($lname, mb_strtolower((string) $p->name), $percent);
            if (in_array($p->id, $scientific, true)) {
                $percent = max($percent, 95.0);
            }
            if ($percent >= $threshold) {
                $scored[] = [
                    'id'     => (int) $p->id,
                    'name'   => $p->name,
                    'slug'   => $p->slug,
                    'status' => $p->status,
                    'score'  => round($percent, 1),
                ];
            }
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $limit);
    }

    /**
     * GET product-search/similar?name=&type_id= — pre-submit near-duplicate check
     * for the "Request new product" flow (so vendors attach to an existing catalog
     * item instead of proposing a duplicate).
     */
    public function similar(Request $request)
    {
        $name = (string) $request->input('name', '');
        $typeId = $request->filled('type_id') ? (int) $request->input('type_id') : null;
        return ['similar' => $this->similarProducts($name, $typeId)];
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
            $product = $this->repository->where('language', DEFAULT_LANGUAGE)->where('slug', $slug)->orWhere('id', $slug)->firstOrFail();
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
        if ($this->canManageProduct($request->user(), $request->id)) {
            // Master-catalog invariant a vendor edit must not break: ownership stays
            // with the master shop. The vendor keeps control of the product status
            // (publish / unpublish / draft) — edits no longer force a re-review.
            $user = $request->user();
            if (!($user && $user->hasPermissionTo(Permission::SUPER_ADMIN))) {
                // A vendor may edit their live product, but not hide (unpublish/
                // draft/reject) one that other vendors are already supplying.
                $product = Product::find((int) $request->id);
                $hidingStatuses = [
                    \Marvel\Enums\ProductStatus::UNPUBLISH,
                    \Marvel\Enums\ProductStatus::DRAFT,
                    \Marvel\Enums\ProductStatus::REJECTED,
                ];
                if ($product
                    && in_array($request->status, $hidingStatuses, true)
                    && $this->vendorBlockedOnSharedLive($user, $product)) {
                    throw new AuthorizationException('This product is being sold by other vendors — ask an admin to unpublish or remove it.');
                }
                $request->merge([
                    'shop_id' => \Marvel\Database\Models\Shop::masterId(),
                ]);
            }
            $id = $request->id;
            $product = $this->repository->updateProduct($request, $id, $setting);
            $this->bustResponseCache('products'); // refresh storefront list/PDP caches (incl. bundle/add-on edits)
            return $product;
        } else {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
    }

    /**
     * Single-shop management gate: super admin manages everything; a store owner
     * may manage the products THEY created (matched via proposed_by_shop_id) at
     * any status, including live — but never admin-created or other vendors' products.
     */
    private function canManageProduct($user, $productId): bool
    {
        if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            return true;
        }
        if (!$user || !$user->hasPermissionTo(Permission::STORE_OWNER) || !$productId) {
            return false;
        }
        $product = Product::find((int) $productId);
        if (!$product || !$product->proposed_by_shop_id) {
            return false;
        }
        return \Marvel\Database\Models\Shop::where('owner_id', $user->id)
            ->where('id', (int) $product->proposed_by_shop_id)
            ->exists();
    }

    /**
     * Shared-catalog integrity: a vendor may create and edit their products, but
     * must NOT unilaterally delete or hide (unpublish/draft/reject) a LIVE product
     * that OTHER vendors are already supplying — that would take a shared, revenue-
     * generating SKU off the storefront for everyone. Those actions are admin-only.
     * Returns true when the action must be blocked for this store owner.
     */
    private function vendorBlockedOnSharedLive($user, Product $product): bool
    {
        if (!$user || $user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            return false; // admins may manage any catalog product
        }
        if ($product->status !== \Marvel\Enums\ProductStatus::PUBLISH) {
            return false; // only live products are shared on the storefront
        }
        $vendorShopId = $this->vendorActiveShopId($user);
        return \Marvel\Database\Models\VendorProductPrice::where('product_id', $product->id)
            ->when($vendorShopId, fn ($q) => $q->where('shop_id', '!=', $vendorShopId))
            ->exists();
    }

    /**
     * POST update-product-status { id, status } — the admin review queue's
     * lightweight approve/reject (no full product payload round-trip).
     */
    public function updateStatus(Request $request)
    {
        if (!($request->user() && $request->user()->hasPermissionTo(Permission::SUPER_ADMIN))) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        $data = $request->validate([
            'id'     => ['required', 'integer', 'exists:products,id'],
            'status' => ['required', 'in:publish,rejected,under_review,draft,unpublish'],
        ]);
        $product = Product::findOrFail((int) $data['id']);
        $product->status = $data['status'];
        $product->save();
        $this->bustResponseCache('products');
        // A newly-published proposal becomes attachable/visible; refresh projections.
        try {
            (new \Marvel\Services\AvailabilityService())->recomputeForProduct((int) $product->id);
        } catch (\Throwable $e) {
            // projection refresh must never fail the status change
        }
        return $product->fresh();
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
            // Single-shop gate: super admin, or a vendor managing their OWN product.
            if ($this->canManageProduct($request->user(), $product->id)) {
                // ...but a vendor may not delete a live product other vendors sell.
                if ($this->vendorBlockedOnSharedLive($request->user(), $product)) {
                    throw new AuthorizationException('This product is being sold by other vendors — ask an admin to remove it.');
                }
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
                    throw new MarvelException("PLANTATHOME_ERROR.WRONG_CSV");
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
                    throw new MarvelException("PLANTATHOME_ERROR.WRONG_CSV");
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
                $type = Type::where('slug', $request->type_slug)->where('language', DEFAULT_LANGUAGE)->firstOrFail();
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
            $products_query = $this->repository->withCount('orders')->with(['type', 'shop'])->orderBy('orders_count', 'desc')->where('language', DEFAULT_LANGUAGE);
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
                $type = Type::where('slug', $request->type_slug)->where('language', DEFAULT_LANGUAGE)->firstOrFail();
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
                ->where('language', DEFAULT_LANGUAGE)
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

        $products_query = $this->repository->with(['type', 'shop', 'proposedByShop'])->where('language', DEFAULT_LANGUAGE);

        // Single-shop model: catalog products all belong to the master shop, so
        // draft/review scoping is by PROPOSER, not owner. Super admin sees the whole
        // platform queue; a vendor sees products they proposed (legacy own-shop rows
        // keep matching for pre-migration data).
        switch ($user) {
            case $user->hasPermissionTo(Permission::SUPER_ADMIN):
                return $products_query;
                break;

            case $user->hasPermissionTo(Permission::STORE_OWNER):
                $shopIds = isset($request->shop_id)
                    ? [(int) $request->shop_id]
                    : $user->shops->pluck('id')->all();
                return $products_query->where(function ($q) use ($shopIds) {
                    $q->whereIn('proposed_by_shop_id', $shopIds)->orWhereIn('shop_id', $shopIds);
                });
                break;

            case $user->hasPermissionTo(Permission::STAFF):
                $staffShopId = isset($request->shop_id) ? (int) $request->shop_id : optional($user->managed_shop)->id;
                return $products_query->where(function ($q) use ($staffShopId) {
                    $q->where('proposed_by_shop_id', $staffShopId)->orWhere('shop_id', $staffShopId);
                });
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

        $products_query = $this->repository->with(['type', 'shop'])->where('language', DEFAULT_LANGUAGE)->where('quantity', '<', 10);

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
