<?php

namespace Marvel\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Address;
use Marvel\Database\Repositories\AddressRepository;
use Marvel\Enums\Permission;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\AddressRequest;
use Marvel\Services\ReverseGeocodeService;
use Prettus\Validator\Exceptions\ValidatorException;

class AddressController extends CoreController
{
    public $repository;

    public function __construct(AddressRepository $repository)
    {
        $this->repository = $repository;
    }


    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Collection|Address[]
     */
    public function index(Request $request)
    {
        $user = $request->user();
        // Customers only ever see their own addresses; super-admin sees all.
        if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            return $this->repository->with('customer')->all();
        }
        return $this->repository->with('customer')->findWhere(['customer_id' => optional($user)->id]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param AddressRequest $request
     * @return mixed
     * @throws ValidatorException
     */
    public function store(AddressRequest $request)
    {
        try {
            $data = Address::sanitizePayload($request->validated());
            // Never trust a client-supplied customer_id — bind to the caller.
            $data['customer_id'] = $request->user()->id;
            $data = $this->withReverseGeocode($data);
            $address = $this->repository->create($data);
            if (!empty($data['default'])) {
                $address->makeSoleDefault();
            }
            return $address;
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param $id
     * @return JsonResponse
     */
    public function show($id, Request $request)
    {
        try {
            $address = $this->repository->with('customer')->findOrFail($id);
            $this->authorizeOwner($address, $request);
            return $address;
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param AddressRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(AddressRequest $request, $id)
    {
        try {
            $address = $this->repository->findOrFail($id);
            $this->authorizeOwner($address, $request);
            $data = Address::sanitizePayload($request->validated());
            unset($data['customer_id']); // never re-parent an address to another user
            $data = $this->withReverseGeocode($data);
            $address->update($data);
            if (!empty($data['default'])) {
                $address->makeSoleDefault();
            }
            return $address;
        } catch (MarvelException $e) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    /**
     * Shopping-City redesign: rg_* (reverse-geocoded city/district/state/pincode) are
     * SERVER-derived from the map-pin coordinates — never accepted from the client —
     * so the checkout shopping-city gate can trust them. Fail-open: geocoding faults
     * leave the rg_* fields untouched (the gate then falls back to the postal master).
     */
    private function withReverseGeocode(array $data): array
    {
        $lat = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $lng = isset($data['longitude']) ? (float) $data['longitude'] : null;
        if (!$lat || !$lng) {
            return $data;
        }
        try {
            $rg = app(ReverseGeocodeService::class)->resolve($lat, $lng);
            $data['rg_city']     = $rg['city'] ?? null;
            $data['rg_district'] = $rg['district'] ?? null;
            $data['rg_state']    = $rg['state'] ?? null;
            $data['rg_pincode']  = $rg['pincode'] ?? null;
        } catch (\Throwable $e) {
            // fail-open
        }
        return $data;
    }

    /** Allow the owner or a super-admin; otherwise 403. */
    private function authorizeOwner($address, Request $request): void
    {
        $user = $request->user();
        if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            return;
        }
        if (!$user || (int) $address->customer_id !== (int) $user->id) {
            throw new MarvelException(NOT_AUTHORIZED);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy($id, Request $request)
    {
        try {
            $user = $request->user();
            if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN)) {
                return $this->repository->findOrFail($id)->delete();
            } else {
                $address = $this->repository->findOrFail($id);
                if ($address->customer_id == $user->id) {
                    return $address->delete();
                }
            }
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }
}
