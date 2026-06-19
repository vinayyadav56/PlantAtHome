<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Marvel\Database\Models\City;
use Marvel\Database\Models\State;
use Marvel\Database\Models\Warehouse;
use Marvel\Exceptions\MarvelException;

/**
 * Master Location System (Phase 2). Public lookups feed the State→City address
 * dropdowns; the admin CRUD + City Activation Engine (super-admin) manage the
 * canonical states/cities/warehouses and gate serviceability by city status.
 */
class LocationController extends CoreController
{
    // ── Public lookups (address dropdowns + storefront) ─────────────────────
    public function states(Request $request)
    {
        return State::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    public function cities(Request $request)
    {
        $query = City::query()
            ->when($request->filled('state_id'), fn ($q) => $q->where('state_id', $request->state_id))
            ->when($request->filled('state'), fn ($q) => $q->where('state_name', $request->state))
            ->when($request->boolean('serviceable'), fn ($q) => $q->where('is_serviceable', true)->whereIn('status', [City::STATUS_ACTIVE, City::STATUS_MAINTENANCE]));

        return $query->orderBy('name')
            ->get(['id', 'name', 'state_id', 'state_name', 'status', 'is_serviceable', 'lat', 'lng']);
    }

    // ── Admin: states ───────────────────────────────────────────────────────
    public function stateIndex(Request $request)
    {
        return State::withCount('cities')->orderBy('name')->paginate((int) ($request->limit ?? 50));
    }

    public function stateStore(Request $request)
    {
        $data = $request->validate([
            'name'      => 'required|string|max:255|unique:states,name',
            'code'      => 'nullable|string|max:8',
            'is_active' => 'nullable|boolean',
        ]);
        return State::create($data);
    }

    public function stateUpdate(Request $request, $id)
    {
        $state = State::findOrFail($id);
        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:255', Rule::unique('states', 'name')->ignore($state->id)],
            'code'      => 'nullable|string|max:8',
            'is_active' => 'nullable|boolean',
        ]);
        $state->update($data);
        return $state;
    }

    public function stateDestroy($id)
    {
        $state = State::findOrFail($id);
        $state->delete();
        return $state;
    }

    // ── Admin: cities + City Activation Engine ──────────────────────────────
    public function cityIndex(Request $request)
    {
        $query = City::with('state:id,name')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->filled('state_id'), fn ($q) => $q->where('state_id', $request->state_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status));

        return $query->orderBy('display_order')->orderBy('name')->paginate((int) ($request->limit ?? 30));
    }

    public function cityShow($id)
    {
        return City::with(['state:id,name', 'warehouses'])->findOrFail($id);
    }

    public function cityStore(Request $request)
    {
        $data = $this->validateCity($request);
        return City::create($data);
    }

    public function cityUpdate(Request $request, $id)
    {
        $city = City::findOrFail($id);
        $city->update($this->validateCity($request, $city->id));
        return $city->fresh('state');
    }

    public function cityDestroy($id)
    {
        $city = City::findOrFail($id);
        $city->delete();
        return $city;
    }

    /** City Activation Engine — flip a city's operational state. */
    public function citySetStatus(Request $request, $id)
    {
        $request->validate(['status' => ['required', Rule::in(City::STATUSES)]]);
        $city = City::findOrFail($id);
        $city->status = $request->input('status');
        // Disabling a city also makes it non-serviceable; re-enabling restores it.
        if ($city->status === City::STATUS_DISABLED) {
            $city->is_serviceable = false;
        } elseif ($city->status === City::STATUS_ACTIVE) {
            $city->is_serviceable = true;
        }
        $city->save();
        return $city->fresh('state');
    }

    protected function validateCity(Request $request, $ignoreId = null): array
    {
        $stateId = $request->input('state_id');
        return $request->validate([
            'name'           => [
                'required', 'string', 'max:255',
                Rule::unique('cities', 'name')->where(fn ($q) => $q->where('state_id', $stateId))->ignore($ignoreId),
            ],
            'state_id'       => 'nullable|integer|exists:states,id',
            'state_name'     => 'nullable|string|max:255',
            'lat'            => 'nullable|numeric',
            'lng'            => 'nullable|numeric',
            'status'         => ['nullable', Rule::in(City::STATUSES)],
            'is_serviceable' => 'nullable|boolean',
            'settings'       => 'nullable|array',
            'display_order'  => 'nullable|integer',
        ]);
    }

    // ── Admin: warehouses ───────────────────────────────────────────────────
    public function warehouseIndex(Request $request)
    {
        return Warehouse::with('city:id,name')
            ->when($request->filled('city_id'), fn ($q) => $q->where('city_id', $request->city_id))
            ->orderByDesc('id')
            ->paginate((int) ($request->limit ?? 30));
    }

    public function warehouseStore(Request $request)
    {
        return Warehouse::create($this->validateWarehouse($request));
    }

    public function warehouseUpdate(Request $request, $id)
    {
        $warehouse = Warehouse::findOrFail($id);
        $warehouse->update($this->validateWarehouse($request));
        return $warehouse->fresh('city');
    }

    public function warehouseDestroy($id)
    {
        $warehouse = Warehouse::findOrFail($id);
        $warehouse->delete();
        return $warehouse;
    }

    protected function validateWarehouse(Request $request): array
    {
        return $request->validate([
            'name'       => 'required|string|max:255',
            'city_id'    => 'nullable|integer|exists:cities,id',
            'address'    => 'nullable|array',
            'lat'        => 'nullable|numeric',
            'lng'        => 'nullable|numeric',
            'capacity'   => 'nullable|integer|min:0',
            'manager_id' => 'nullable|integer|exists:users,id',
            'is_active'  => 'nullable|boolean',
        ]);
    }
}
