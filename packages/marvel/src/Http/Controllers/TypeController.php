<?php

namespace Marvel\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Type;
use Marvel\Database\Repositories\TypeRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\TypeRequest;
use Marvel\Http\Resources\TypeResource;
use Marvel\Traits\ApiResponseCache;
use Illuminate\Support\Facades\Cache;
use Prettus\Validator\Exceptions\ValidatorException;

class TypeController extends CoreController
{
    use ApiResponseCache;

    public $repository;

    public function __construct(TypeRepository $repository)
    {
        $this->repository = $repository;
    }


    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Collection|Type[]
     */
    public function index(Request $request)
    {
        $language = $request->language ?? DEFAULT_LANGUAGE;

        // Types (verticals) rarely change but every storefront page (SSR)
        // fetches them. Server-cache + edge-cache for anonymous reads; admin
        // (real Bearer) stays fresh.
        if (!$this->isPublicCacheable($request)) {
            return TypeResource::collection($this->repository->where('language', $language)->get());
        }

        $key = 'types:v' . $this->cacheVersion('types') . ':' . $language;
        $data = Cache::remember($key, 600, function () use ($language) {
            $types = $this->repository->where('language', $language)->get();
            return TypeResource::collection($types)->response()->getData(true);
        });

        return response()->json($data)->header('Cache-Control', $this->cacheControl());
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param TypeRequest $request
     * @return mixed
     * @throws ValidatorException
     */
    public function store(TypeRequest $request)
    {
        try {
            $type = $this->repository->storeType($request);
            $this->bustResponseCache('types');
            return $type;
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param $slug
     * @return JsonResponse
     */
    public function show(Request $request, $params)
    {

        try {
            $language = $request->language ?? DEFAULT_LANGUAGE;
            if (is_numeric($params)) {
                $params = (int) $params;
                $type = $this->repository->where('id', $params)->with('banners')->firstOrFail();
                return new TypeResource($type);
            }
            $type = $this->repository->where('slug', $params)->where('language', $language)->with('banners')->firstOrFail();
            return new TypeResource($type);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param TypeRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(TypeRequest $request, $id)
    {
        $request->id = $id;
        return $this->updateType($request);
    }

    public function updateType(TypeRequest $request)
    {
        try {
            $type = $this->repository->with('banners')->findOrFail($request->id);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
        $updated = $this->repository->updateType($request, $type);
        $this->bustResponseCache('types');
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
            $deleted = $this->repository->findOrFail($id)->delete();
            $this->bustResponseCache('types');
            return $deleted;
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }
}
