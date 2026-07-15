<?php

namespace App\Modules\Serviceability\Http\Controllers;

use App\Modules\Serviceability\Infrastructure\Models\District;
use App\Modules\Serviceability\Infrastructure\Models\PostalCode;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Public geo master lookups (address forms + coverage pickers): legacy
 * states/cities + the new districts/postal_codes. List reads are cached under
 * the geo:ver version key (bumped by the pincode import); the paginated
 * free-text postal-code search stays uncached.
 */
final class GeoController extends ApiController
{
    private const TTL = 3600;

    /** GET /api/v1/serviceability/geo/states?country_id= */
    public function states(Request $request): JsonResponse
    {
        $countryId = (int) $request->query('country_id', 0);

        $rows = Cache::remember("geo:v{$this->version()}:states:{$countryId}", self::TTL, function () use ($countryId) {
            return DB::table('states')->where('is_active', true)
                ->when($countryId > 0, fn ($q) => $q->where('country_id', $countryId))
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'country_id'])
                ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name, 'code' => $s->code, 'country_id' => $s->country_id !== null ? (int) $s->country_id : null])
                ->all();
        });

        return $this->ok($rows);
    }

    /** GET /api/v1/serviceability/geo/districts?state_id= */
    public function districts(Request $request): JsonResponse
    {
        $stateId = (int) $request->query('state_id', 0);
        if ($stateId <= 0) {
            return $this->fail('MISSING_PARAM', "Query param 'state_id' is required.", 422, 'state_id');
        }

        $rows = Cache::remember("geo:v{$this->version()}:districts:{$stateId}", self::TTL, function () use ($stateId) {
            return District::where('state_id', $stateId)->where('is_active', true)->orderBy('name')
                ->get(['id', 'state_id', 'name'])
                ->map(fn (District $d) => ['id' => $d->id, 'state_id' => $d->state_id, 'name' => $d->name])
                ->all();
        });

        return $this->ok($rows);
    }

    /** GET /api/v1/serviceability/geo/cities?state_id=&district_id= */
    public function cities(Request $request): JsonResponse
    {
        $stateId = (int) $request->query('state_id', 0);
        $districtId = (int) $request->query('district_id', 0);

        $rows = Cache::remember("geo:v{$this->version()}:cities:{$stateId}:{$districtId}", self::TTL, function () use ($stateId, $districtId) {
            return DB::table('cities')
                ->when($stateId > 0, fn ($q) => $q->where('state_id', $stateId))
                ->when($districtId > 0, fn ($q) => $q->where('district_id', $districtId))
                ->where('status', '!=', 'disabled')
                ->orderBy('name')
                ->get(['id', 'name', 'state_id', 'district_id'])
                ->map(fn ($c) => [
                    'id'          => (int) $c->id,
                    'name'        => $c->name,
                    'state_id'    => $c->state_id !== null ? (int) $c->state_id : null,
                    'district_id' => $c->district_id !== null ? (int) $c->district_id : null,
                ])
                ->all();
        });

        return $this->ok($rows);
    }

    /** GET /api/v1/serviceability/geo/postal-codes?district_id=|city_id=|search= (paginated) */
    public function postalCodes(Request $request): JsonResponse
    {
        $districtId = (int) $request->query('district_id', 0);
        $cityId = (int) $request->query('city_id', 0);
        $search = trim((string) $request->query('search', ''));
        if ($districtId <= 0 && $cityId <= 0 && $search === '') {
            return $this->fail('MISSING_PARAM', "One of 'district_id', 'city_id' or 'search' is required.", 422);
        }

        $paginator = PostalCode::query()
            ->where('status', PostalCode::STATUS_ACTIVE)
            ->when($districtId > 0, fn ($q) => $q->where('district_id', $districtId))
            ->when($cityId > 0, fn ($q) => $q->where('city_id', $cityId))
            ->when($search !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('pincode', 'like', $search.'%')
                ->orWhere('office_name', 'like', '%'.$search.'%')))
            ->orderBy('pincode')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 50))));

        return $this->paginated($paginator, fn (PostalCode $p) => [
            'id'          => $p->id,
            'pincode'     => $p->pincode,
            'office_name' => $p->office_name,
            'state_id'    => $p->state_id,
            'district_id' => $p->district_id,
            'city_id'     => $p->city_id,
        ]);
    }

    private function version(): int
    {
        return (int) Cache::get('geo:ver', 0);
    }
}
