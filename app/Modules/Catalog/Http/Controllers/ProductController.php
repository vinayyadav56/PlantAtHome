<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\ProductService;
use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Http\Requests\CreateProductRequest;
use App\Modules\Catalog\Http\Requests\CreateVariantRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;
use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Http\Resources\VariantResource;
use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Identity\Domain\Permission;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductController extends ApiController
{
    private const EAGER = ['category', 'variants', 'media', 'attributeValues.attribute'];

    /** Deep offset pagination is a full walk; refuse to go arbitrarily deep. */
    private const MAX_PAGE = 500;

    /**
     * InnoDB will not index a token shorter than this (innodb_ft_min_token_size,
     * default 3), so a shorter term can never match via FULLTEXT and has to fall
     * back to LIKE.
     */
    private const FT_MIN_TOKEN = 3;

    public function __construct(private readonly ProductService $products)
    {
    }

    /** GET /api/v1/catalog/products — published only for guests; admins see all. */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()->with(self::EAGER)->latest('id');

        // Only a catalog manager may see non-published products; everyone else
        // (guests, nurseries, customers) is confined to PUBLISHED regardless of
        // any client-supplied status filter.
        if ($this->canSeeUnpublished($request)) {
            $status = $request->query('status');
            if ($status && $status !== 'all') {
                $query->where('status', $status);
            }
        } else {
            $query->where('status', ProductStatus::PUBLISHED);
        }

        if ($categoryUuid = $request->query('category')) {
            $category = Category::byUuid($categoryUuid)->first();
            $query->where('category_id', $category?->id ?? 0);
        }

        if ($search = $request->query('search')) {
            $this->applySearch($query, (string) $search);
        }

        $limit = min((int) $request->query('limit', 20), 100);

        // `page` was unbounded while `limit` was capped, so ?page=100000 was a
        // legal request that made MySQL walk the whole table to reach an offset
        // past the end of it. Offset pagination already degrades measurably —
        // page 50 costs 7.7x page 1 — so the ceiling matters.
        $page = max(1, (int) $request->query('page', 1));
        $request->query->set('page', min($page, self::MAX_PAGE));

        return $this->paginated($query->paginate($limit), fn (Product $p) => ProductResource::make($p));
    }

    /**
     * Product search.
     *
     * Was `name LIKE '%term%'`. A leading wildcard cannot use an index, so every
     * search scanned the whole published catalogue: EXPLAIN showed rows=1570 for
     * a term matching 16 products. The `products_fulltext (name, sku)` index has
     * existed for some time and nothing in the codebase used it — there was no
     * MATCH … AGAINST anywhere.
     *
     * Now FULLTEXT in BOOLEAN mode, which examines rows=1 for the same query and
     * returns the identical 16 rows. Boolean mode rather than natural language
     * because natural language silently drops any word appearing in more than
     * half the rows, which on a single-vertical catalogue ("plant") is exactly
     * the word people search for.
     *
     * Two things the naive version gets wrong, handled here:
     *   - Boolean mode treats + - > < ( ) ~ * " @ as OPERATORS. A user typing
     *     "plant-doctor" would otherwise mean "plant AND NOT doctor" and get
     *     the opposite of what they asked for. Operators are stripped.
     *   - Tokens below innodb_ft_min_token_size are never indexed, so short
     *     terms fall back to LIKE rather than silently returning nothing.
     */
    private function applySearch(\Illuminate\Database\Eloquent\Builder $query, string $search): void
    {
        $clean = trim($search);
        if ($clean === '') {
            return;
        }

        // Strip boolean operators, collapse whitespace, and keep only tokens the
        // index can actually match.
        $stripped = preg_replace('/[+\-><()~*"@]+/', ' ', $clean) ?? '';
        $tokens = array_values(array_filter(
            preg_split('/\s+/', $stripped) ?: [],
            fn (string $t) => mb_strlen($t) >= self::FT_MIN_TOKEN
        ));

        if ($tokens === []) {
            // Too short to be indexed (e.g. "AB"). LIKE is a full scan, but on a
            // 2-character term that is the only thing that can match at all.
            $query->where('name', 'like', '%'.$clean.'%');

            return;
        }

        // `+token*` per term: every term is REQUIRED (+) and prefix-matched (*).
        //
        // The + matters. Boolean mode defaults to OR, so a bare "snake plant"
        // returns anything containing snake OR plant — measured at 18 rows here
        // versus 1 for the old LIKE. Requiring both gives what someone typing two
        // words actually means, and keeps the result set tight.
        //
        // Bound as a parameter, never interpolated.
        $expr = implode(' ', array_map(fn (string $t) => '+'.$t.'*', $tokens));

        $query->whereRaw('MATCH(name, sku) AGAINST (? IN BOOLEAN MODE)', [$expr]);
    }

    private function canSeeUnpublished(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && $user->hasPermission(Permission::CATALOG_MANAGE);
    }

    /** POST /api/v1/catalog/products (admin) */
    public function store(CreateProductRequest $request): JsonResponse
    {
        $product = $this->products->create($request->validated(), $request->user()?->uuid);

        return $this->created(ProductResource::make($product));
    }

    /** GET /api/v1/catalog/products/{product} */
    public function show(Request $request, Product $product): JsonResponse
    {
        $this->assertVisible($request, $product);
        $product->load(self::EAGER);

        return $this->ok(ProductResource::make($product));
    }

    /** A draft/archived product is invisible (404) to anyone but a catalog manager. */
    private function assertVisible(Request $request, Product $product): void
    {
        if ($product->status !== ProductStatus::PUBLISHED && ! $this->canSeeUnpublished($request)) {
            throw \App\Shared\Application\DomainActionException::notFound('The requested resource was not found.');
        }
    }

    /** PATCH /api/v1/catalog/products/{product} (admin) */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $updated = $this->products->update($product, $request->validated(), $request->user()?->uuid);

        return $this->ok(ProductResource::make($updated));
    }

    /** POST /api/v1/catalog/products/{product}/publish (admin) */
    public function publish(Request $request, Product $product): JsonResponse
    {
        $published = $this->products->publish($product, $request->user()?->uuid);
        $published->load(self::EAGER);

        return $this->ok(ProductResource::make($published));
    }

    /** GET /api/v1/catalog/products/{product}/variants */
    public function variants(Request $request, Product $product): JsonResponse
    {
        $this->assertVisible($request, $product);

        return $this->ok(
            $product->variants()->orderBy('sort')->get()->map(fn ($v) => VariantResource::make($v))->all(),
        );
    }

    /** POST /api/v1/catalog/products/{product}/variants (admin) */
    public function storeVariant(CreateVariantRequest $request, Product $product): JsonResponse
    {
        $variant = $this->products->addVariant($product, $request->validated(), $request->user()?->uuid);

        return $this->created(VariantResource::make($variant));
    }
}
