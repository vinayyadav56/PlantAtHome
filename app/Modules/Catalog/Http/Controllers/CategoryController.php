<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\CategoryService;
use App\Modules\Catalog\Http\Requests\CreateCategoryRequest;
use App\Modules\Catalog\Http\Requests\UpdateCategoryRequest;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CategoryController extends ApiController
{
    public function __construct(private readonly CategoryService $categories)
    {
    }

    /** GET /api/v1/catalog/categories — flat list ordered by tree position. */
    public function index(Request $request): JsonResponse
    {
        $query = Category::query()->with('parent')->orderBy('path')->orderBy('sort');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
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
    public function show(Category $category): JsonResponse
    {
        $category->load(['parent', 'children']);

        return $this->ok(CategoryResource::make($category));
    }

    /** PATCH /api/v1/catalog/categories/{category} (admin) */
    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $updated = $this->categories->update($category, $request->validated(), $request->user()?->uuid);

        return $this->ok(CategoryResource::make($updated));
    }
}
