<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\CategoryService;
use App\Modules\Catalog\Http\Requests\CreateCategoryRequest;
use App\Modules\Catalog\Http\Requests\UpdateCategoryRequest;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Modules\Identity\Domain\Permission;
use App\Shared\Application\DomainActionException;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CategoryController extends ApiController
{
    private const ACTIVE = 'active';

    public function __construct(private readonly CategoryService $categories)
    {
    }

    /** GET /api/v1/catalog/categories — flat list ordered by tree position. */
    public function index(Request $request): JsonResponse
    {
        $query = Category::query()->with('parent')->orderBy('path')->orderBy('sort');

        // Only a catalog manager may see inactive categories; everyone else
        // (guests, nurseries, customers) is confined to ACTIVE regardless of any
        // client-supplied status filter. Mirrors ProductController.
        if ($this->canManage($request)) {
            if ($status = $request->query('status')) {
                $query->where('status', $status);
            }
        } else {
            $query->where('status', self::ACTIVE);
        }

        return $this->ok($query->get()->map(fn (Category $c) => CategoryResource::make($c))->all());
    }

    /** POST /api/v1/catalog/categories (admin) */
    public function store(CreateCategoryRequest $request): JsonResponse
    {
        $category = $this->categories->create($request->validated(), $request->user()?->uuid);

        return $this->created(CategoryResource::make($category));
    }

    /** GET /api/v1/catalog/categories/{category} */
    public function show(Request $request, Category $category): JsonResponse
    {
        $manager = $this->canManage($request);
        if ($category->status !== self::ACTIVE && ! $manager) {
            throw DomainActionException::notFound('The requested resource was not found.');
        }
        // Hide inactive children from non-managers too.
        $category->load(['parent', 'children' => fn ($q) => $manager ? $q : $q->where('status', self::ACTIVE)]);

        return $this->ok(CategoryResource::make($category));
    }

    private function canManage(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && $user->hasPermission(Permission::CATALOG_MANAGE);
    }

    /** PATCH /api/v1/catalog/categories/{category} (admin) */
    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $updated = $this->categories->update($category, $request->validated(), $request->user()?->uuid);

        return $this->ok(CategoryResource::make($updated));
    }
}
