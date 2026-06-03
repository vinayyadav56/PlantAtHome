<?php


namespace Marvel\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Category;
use Marvel\Database\Repositories\CategoryRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\CategoryCreateRequest;
use Marvel\Http\Requests\CategoryUpdateRequest;
use Marvel\Http\Resources\CategoryResource;
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
        $limit = $request->limit ?? 15;
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
        $ver = \Illuminate\Support\Facades\Cache::get('categories:ver', 1);
        $key = "categories:c3:v{$ver}:{$language}:{$parent}:{$selfId}:{$limit}:{$page}:{$typeSlug}";

        // Cache the plain data ARRAY (not the JsonResponse — that would
        // re-serialize to {headers,original,exception} and break the shop).
        $data = \Illuminate\Support\Facades\Cache::remember($key, 600, function () use ($language, $parent, $selfId, $limit, $typeSlug) {
            $categoriesQuery = $this->repository->with(['type', 'parent', 'children'])
                ->where('language', $language);

            if ($typeSlug) {
                $categoriesQuery->whereHas('type', function ($q) use ($typeSlug) {
                    $q->where('slug', $typeSlug);
                });
            }
            if ($parent === 'null') {
                $categoriesQuery->whereNull('parent');
            }
            if ($selfId) {
                $categoriesQuery->where('id', '!=', $selfId);
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
        $ver = (int) \Illuminate\Support\Facades\Cache::get('categories:ver', 1);
        \Illuminate\Support\Facades\Cache::forever('categories:ver', $ver + 1);
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
            $this->bustCategoryCache();
            return $this->repository->saveCategory($request);
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
            if (is_numeric($params)) {
                $params = (int) $params;
                $category = $this->repository->with(['type', 'parentCategory', 'children'])->where('id', $params)->firstOrFail();
                return new CategoryResource($category);
            }
            $category = $this->repository->with(['type', 'parentCategory', 'children'])->where('slug', $params)->firstOrFail();
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
        $this->bustCategoryCache();
        return $this->repository->updateCategory($request, $category);
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
            $this->bustCategoryCache();
            return $this->repository->findOrFail($id)->delete();
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }
}
