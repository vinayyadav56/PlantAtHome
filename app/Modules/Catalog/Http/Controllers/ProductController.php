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
            $query->where('name', 'like', '%'.$search.'%');
        }

        $limit = min((int) $request->query('limit', 20), 100);

        return $this->paginated($query->paginate($limit), fn (Product $p) => ProductResource::make($p));
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
