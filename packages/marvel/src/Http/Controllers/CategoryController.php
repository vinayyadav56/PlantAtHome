<?php


namespace Marvel\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Category;
use Marvel\Database\Repositories\CategoryRepository;
use Marvel\Enums\Permission;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\CategoryCreateRequest;
use Marvel\Http\Requests\CategoryUpdateRequest;
use Marvel\Http\Resources\CategoryResource;
use Marvel\Services\CategoryTree;
use Prettus\Validator\Exceptions\ValidatorException;


class CategoryController extends CoreController
{
    public $repository;

    public function __construct(CategoryRepository $repository)
    {
        $this->repository = $repository;
    }

    // /**
    //  * Display a listing of the resource.
    //  *
    //  * @param Request $request
    //  * @return Collection|Category[]
    //  */
    // public function fetchOnlyParent(Request $request)
    // {
    //     $limit = $request->limit ?   $request->limit : 15;
    //     return $this->repository->withCount(['products'])->with(['type', 'parent', 'children'])->where('parent', null)->paginate($limit);
    //     // $limit = $request->limit ?   $request->limit : 15;
    //     // return $this->repository->withCount(['children', 'products'])->with(['type', 'parent', 'children.type', 'children.children.type', 'children.children' => function ($query) {
    //     //     $query->withCount('products');
    //     // },  'children' => function ($query) {
    //     //     $query->withCount('products');
    //     // }])->where('parent', null)->paginate($limit);
    // }

    // /**
    //  * Display a listing of the resource.
    //  *
    //  * @param Request $request
    //  * @return Collection|Category[]
    //  */
    // public function fetchCategoryRecursively(Request $request)
    // {
    //     $limit = $request->limit ?   $request->limit : 15;
    //     return $this->repository->withCount(['products'])->with(['parent', 'subCategories'])->where('parent', null)->paginate($limit);
    // }
    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Collection|Category[]
     */
    public function index(Request $request)
    {
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $parent = $request->parent;
        $selfId = $request->self;
        // Clamp to 100 (mirrors ProductController). With the recursive `children`
        // eager-load, serializing the full category forest at limit=1000 blew the
        // PHP-FPM memory_limit mid-json_encode on Railway, so nginx truncated the
        // chunked body → the storefront's JSON.parse failed (dead filters/grids).
        $limit = min(max((int) ($request->limit ?: 15), 1), 100);
        $page = $request->page ?? 1;

        // The storefront filters per vertical via `search=type.slug:plants`
        // (or a bare `type` param). Resolve the vertical slug and apply the
        // filter EXPLICITLY below — don't rely on RequestCriteria firing
        // inside the cache closure — and make it part of the cache key, else
        // every vertical collides on one entry and an unfiltered request
        // poisons the rest (plants would show tool/farmbox categories).
        $typeSlug = $request->type ?? '';
        if (!$typeSlug && ($search = $request->search) && preg_match('/type\.slug:([^;]+)/', $search, $m)) {
            $typeSlug = $m[1];
        }

        // Categories rarely change but the storefront filter sidebar requests
        // them (with the recursive children eager-load) on every load — cache
        // the formatted payload, busted by a version key on any category write.
        // `c3` is a static code version — bump it to abandon any old poisoned
        // cache entries (c2 omitted the type from the key → cross-vertical mix).
        // Homepage placement filter, opt-in via ?home=1. Off by default so every
        // existing caller (filter sidebar, mega-menu, admin) is untouched.
        $homeOnly = filter_var($request->home ?? false, FILTER_VALIDATE_BOOLEAN);
        // Switched-on categories in display order (the Tools surfaces). Opt-in, like `home`,
        // so every existing caller is untouched.
        $activeOnly = filter_var($request->active ?? false, FILTER_VALIDATE_BOOLEAN);
        // A bare list (no vertical, no parent, no search) returns ROOTS only. The released app
        // asks exactly that for its chip strip, and the 3-level Tools taxonomy would otherwise
        // fill it with hundreds of sub-categories. Before the taxonomy the whole catalogue held
        // two child rows, so this is what that request effectively always returned.
        $rootsOnly = $typeSlug === '' && $parent === null && !$request->filled('search');
        $hasOrder = $this->hasCategoryColumn('display_order');

        $ver = \Illuminate\Support\Facades\Cache::get('categories:ver', 1);
        // ⚠️ EVERY filter must appear in this key. c2 omitted the type and one
        // unfiltered request poisoned every vertical; `home` is the same hazard.
        // c5 adds active/roots and the Prettus search + sort (admin name search and
        // column sort used to be served whichever result was cached first).
        $key = "categories:c5:v{$ver}:{$language}:{$parent}:{$selfId}:{$limit}:{$page}:{$typeSlug}:" . ($homeOnly ? 'home' : 'all')
            . ($activeOnly ? ':active' : '') . ($rootsOnly ? ':roots' : '')
            . ':' . md5($request->search . '|' . $request->orderBy . '|' . $request->sortedBy);

        // Cache the plain data ARRAY (not the JsonResponse — that would
        // re-serialize to {headers,original,exception} and break the shop).
        $data = \Illuminate\Support\Facades\Cache::remember($key, 600, function () use ($language, $parent, $selfId, $limit, $typeSlug, $homeOnly, $activeOnly, $rootsOnly, $hasOrder) {
            // N+1 fix: CategoryResource reads $this->parentCategory (the show() path at :155/:158
            // already eager-loads 'parentCategory'); 'parent' was loaded-but-unused while
            // parentCategory lazy-loaded per row.
            // Translation overlay: always read the canonical English row; the
            // requested language is applied at read-time by the overlay (the
            // per-language response cache key still keys on $language).
            // Children: ONE level (that is all the resource serializes) and in display order.
            // The relation itself recurses, which loaded every grandchild of a 3-level tree.
            $categoriesQuery = $this->repository->with(['type', 'parentCategory', 'children' => function ($q) use ($hasOrder) {
                $q->without('children');
                if ($hasOrder) {
                    $q->orderBy('display_order');
                }
                $q->orderBy('name');
            }])
                ->where('language', DEFAULT_LANGUAGE);

            if ($typeSlug) {
                $categoriesQuery->whereHas('type', function ($q) use ($typeSlug) {
                    $q->where('slug', $typeSlug);
                });
            }
            if ($parent === 'null' || $rootsOnly) {
                $categoriesQuery->whereNull('parent');
            }
            if ($activeOnly && !$homeOnly) {
                $categoriesQuery->where('is_active', true);
                if ($hasOrder) {
                    $categoriesQuery->orderBy('display_order');
                }
                $categoriesQuery->orderBy('name');
            }
            if ($selfId) {
                $categoriesQuery->where('id', '!=', $selfId);
            }
            if ($homeOnly) {
                // Ordered here rather than in the client so every consumer of
                // ?home=1 agrees, and so paging cannot interleave.
                // name is the tie-break because sort_order defaults to 0 for all.
                $categoriesQuery->where('show_on_homepage', true)
                    ->where('is_active', true)
                    ->orderBy('homepage_sort_order')
                    ->orderBy('name');
            }

            $categories = $categoriesQuery->paginate($limit);
            return CategoryResource::collection($categories)->response()->getData(true);
        });

        return formatAPIResourcePaginate($data)
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=600');
    }

    /** Invalidate the categories cache (called on any category write). */
    protected function bustCategoryCache(): void
    {
        CategoryTree::bust();
    }

    /**
     * GET /categories/tree?type=tools[&all=1] — the whole hierarchy of a vertical (every vertical
     * without `type`), nested and in display order, each node with its listable products rolled
     * up through its sub-tree and placements. `all=1` (super admin only) keeps switched-off
     * categories, for the admin tree. Cached on the category and product versions.
     */
    public function tree(Request $request)
    {
        $typeSlug = trim((string) ($request->type ?? ''));
        $all = filter_var($request->all ?? false, FILTER_VALIDATE_BOOLEAN) && $this->isSuperAdmin($request);
        $typeId = null;
        if ($typeSlug !== '') {
            $typeId = DB::table('types')->where('slug', $typeSlug)->orderBy('id')->value('id');
            if (!$typeId) {
                return response()->json(['data' => []]);
            }
            $typeId = (int) $typeId;
        }

        $key = 'categories:tree-api:v' . CategoryTree::version() . ':p' . (int) Cache::get('products:ver', 1)
            . ':' . $typeSlug . ':' . ($all ? 'all' : 'live');
        $data = Cache::remember($key, 600, function () use ($typeId, $all) {
            $tree = app(CategoryTree::class);

            return $tree->tree($typeId, $all, $tree->listableCounts($typeId));
        });

        return response()->json(['data' => $data])
            ->header('Cache-Control', $all ? 'private, no-store' : 'public, max-age=60, s-maxage=300, stale-while-revalidate=600');
    }

    /**
     * A category page's children, from the hierarchy: switched-on only, in display order, with the
     * categories placed here included (flagged `placement`) and each one's listable products rolled
     * up through its own sub-tree. Replaces the recursive `children` eager load on show().
     */
    private function attachTreeChildren(Category $category): void
    {
        $tree = app(CategoryTree::class);
        $entries = $tree->children((int) $category->id);
        if (!$entries) {
            $category->setRelation('children', collect());

            return;
        }
        $models = Category::query()->whereIn('id', array_column($entries, 'id'))->get()->keyBy('id');
        $counts = $tree->listableCounts((int) $category->type_id);
        $children = collect($entries)->map(function ($entry) use ($models, $counts) {
            $child = $models->get($entry['id']);
            if (!$child || (isset($child->is_active) && !$child->is_active)) {
                return null;
            }
            $child->setAttribute('products_count', $counts[$entry['id']] ?? 0);
            $child->setAttribute('placement', $entry['placement']);

            return $child;
        })->filter()->values();
        $category->setRelation('children', $children);
    }

    private function isSuperAdmin(Request $request): bool
    {
        try {
            $user = $request->user() ?? $request->user('sanctum');

            return $user && $user->hasPermissionTo(Permission::SUPER_ADMIN);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Per instance, never static: a process-wide memo outlives the schema it was read from (tests). */
    private array $knownColumns = [];

    private function hasCategoryColumn(string $column): bool
    {
        return $this->knownColumns[$column] ??= Schema::hasColumn('categories', $column);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param CategoryCreateRequest $request
     * @return mixed
     * @throws ValidatorException
     */
    public function store(CategoryCreateRequest $request)
    {
        try {
            $category = $this->repository->saveCategory($request);
            // After the write: a bump before it let a concurrent read re-cache the old list.
            $this->bustCategoryCache();
            return $category;
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
        // $language = $request->language ?? DEFAULT_LANGUAGE;
        // $translation_item_id = $request->translation_item_id ?? null;
        // $category->storeTranslation($translation_item_id, $language);
        // return $category;
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(Request $request, $params)
    {
        try {
            $language = $request->language ?? DEFAULT_LANGUAGE;
            $query = $this->repository->with(['type', 'parentCategory']);
            $category = is_numeric($params)
                ? $query->where('id', (int) $params)->firstOrFail()
                : $query->where('slug', $params)->firstOrFail();
            $this->attachTreeChildren($category);
            return new CategoryResource($category);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param CategoryUpdateRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(CategoryUpdateRequest $request, $id)
    {
        try {
            $request->merge(['id' => $id]);
            return $this->categoryUpdate($request);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }


    public function categoryUpdate(CategoryUpdateRequest $request)
    {
        $category = $this->repository->findOrFail($request->id);
        $updated = $this->repository->updateCategory($request, $category);
        $this->bustCategoryCache();
        return $updated;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        try {
            $category = $this->repository->findOrFail($id);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }

        // A delete is a hard delete that cascades to sub-categories and product links (no soft
        // delete on Category, FKs ON DELETE CASCADE). Only an unused category may go; anything
        // in use is switched off (archived) instead, which keeps its links and URL.
        $children = DB::table('categories')->where('parent', $category->id)->count();
        $placements = Schema::hasTable('category_placements')
            ? DB::table('category_placements')->where('category_id', $category->id)->orWhere('parent_id', $category->id)->count()
            : 0;
        $products = DB::table('category_product')->where('category_id', $category->id)->count();
        // An attribute scoped here would lose its scope (and its required flag) with the row.
        $attributes = Schema::hasTable('plant_attribute_scopes')
            ? DB::table('plant_attribute_scopes')->where('category_id', $category->id)->count()
            : 0;
        if ($children || $placements || $products || $attributes) {
            $uses = array_filter([
                $children ? "{$children} sub-categories" : null,
                $placements ? "{$placements} placements" : null,
                $products ? "{$products} products" : null,
                $attributes ? "{$attributes} product attributes" : null,
            ]);

            return response()->json([
                'message'    => 'This category is still in use (' . implode(', ', $uses) . '). Switch it off (archive it) instead of deleting it.',
                'children'   => $children,
                'placements' => $placements,
                'products'   => $products,
                'attributes' => $attributes,
            ], 422);
        }

        $deleted = $category->delete();
        $this->bustCategoryCache();
        return $deleted;
    }
}
