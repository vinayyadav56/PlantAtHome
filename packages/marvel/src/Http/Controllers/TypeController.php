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
        // Admin (real Bearer) always sees the full, unfiltered vertical list.
        // Overlay i18n model: rows exist ONLY in DEFAULT_LANGUAGE — localized
        // fields are merged by the translation overlay, so always query the
        // canonical rows (a `language=hi` row filter would return nothing).
        if (!$this->isPublicCacheable($request)) {
            return TypeResource::collection($this->repository->where('language', DEFAULT_LANGUAGE)->get());
        }

        // Storefront: hide verticals the Operations Control Center has turned off
        // — globally, or in the shopper's city when a `city` is provided — so a
        // disabled vertical disappears from the nav immediately. The cache key
        // carries the availability version + city, so any Operations toggle
        // invalidates it instantly; the short edge TTL bounds CDN staleness.
        $availSvc = app(\Marvel\Services\ServiceAvailabilityService::class);
        $city = $request->filled('city') ? (string) $request->city : null;
        $cityKey = \Marvel\Services\ServiceAvailabilityService::norm($city);
        $availVer = (int) Cache::get('service_availability:ver', 1);

        $key = 'types:v' . $this->cacheVersion('types')
            . ':a' . $availVer
            . ':' . ($cityKey !== '' ? $cityKey : '_')
            . ':' . $language;

        $data = Cache::remember($key, 600, function () use ($availSvc, $city) {
            // Canonical rows only — the overlay localizes fields per request
            // language (the cache key above still varies by $language).
            $types = $this->repository->where('language', DEFAULT_LANGUAGE)->get();
            // FAIL OPEN: only narrow when something is actually disabled, and
            // never hide EVERY vertical (an explicit all-off is the platform
            // kill-switch, handled elsewhere — don't blank the storefront here).
            $available = $availSvc->availableVerticalsForCity($city);
            $all = $availSvc->allVerticals();
            if (count($available) > 0 && count($available) < count($all)) {
                $types = $types->filter(fn ($t) => in_array($t->slug, $available, true))->values();
            }
            return TypeResource::collection($types)->response()->getData(true);
        });

        // Short shared TTL so an Operations toggle propagates to the CDN in
        // seconds (the version-keyed server cache keeps origin cheap).
        return response()->json($data)->header('Cache-Control', $this->cacheControl(20));
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
            // Canonical row lookup (slugs are not translated in the overlay model).
            $type = $this->repository->where('slug', $params)->where('language', DEFAULT_LANGUAGE)->with('banners')->firstOrFail();
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
