<?php

namespace App\Modules\Serviceability\Http\Controllers;

use App\Modules\Serviceability\Application\CoverageService;
use App\Modules\Serviceability\Infrastructure\Models\City;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Cities + city-level serviceability reads. */
final class CityController extends ApiController
{
    public function __construct(private readonly CoverageService $coverage)
    {
    }

    /** GET /api/v1/serviceability/cities — public list of active cities. */
    public function index(): JsonResponse
    {
        return $this->ok(
            City::where('is_active', true)->orderBy('name')->get()
                ->map(fn (City $c) => ['uuid' => $c->uuid, 'name' => $c->name, 'state' => $c->state])->all(),
        );
    }

    /** POST /api/v1/serviceability/cities (admin) */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['name' => ['required', 'string', 'max:255'], 'state' => ['nullable', 'string', 'max:255']]);
        $city = $this->coverage->createCity($request->input('name'), $request->input('state'));

        return $this->created(['uuid' => $city->uuid, 'name' => $city->name, 'state' => $city->state]);
    }

    /** GET /api/v1/serviceability/cities/{city}/serviceable — public. */
    public function serviceable(City $city): JsonResponse
    {
        $vendors = $this->coverage->vendorsServingCity($city->uuid);

        return $this->ok([
            'city_uuid'       => $city->uuid,
            'serviceable'     => $vendors !== [],
            'serving_vendors' => $vendors,
        ]);
    }
}
