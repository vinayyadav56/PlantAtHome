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
        // Delivery Coverage — cities can be filtered by their district once the
        // geo-master migration has landed (guarded: the column arrives with the
        // Serviceability module's migrations, which can lag the code deploy).
        $hasDistrict = \Illuminate\Support\Facades\Schema::hasColumn('cities', 'district_id');

        $query = City::query()
            ->when($request->filled('state_id'), fn ($q) => $q->where('state_id', $request->state_id))
            ->when($request->filled('state'), fn ($q) => $q->where('state_name', $request->state))
            ->when($hasDistrict && $request->filled('district_id'), fn ($q) => $q->where('district_id', $request->district_id))
            ->when($request->boolean('serviceable'), fn ($q) => $q->where('is_serviceable', true)->whereIn('status', [City::STATUS_ACTIVE, City::STATUS_MAINTENANCE]));

        $columns = ['id', 'name', 'state_id', 'state_name', 'status', 'is_serviceable', 'lat', 'lng'];
        if ($hasDistrict) {
            $columns[] = 'district_id';
        }

        return $query->orderBy('name')->get($columns);
    }

    /**
     * Public: active districts for a state (coverage pickers + address forms).
     * Cached under the geo master version (bumped by any geo admin write).
     */
    public function districts(Request $request)
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('districts')) {
            return [];
        }
        $stateId = (int) $request->query('state_id', 0);
        $ver = (int) \Illuminate\Support\Facades\Cache::get('geo:ver', 0);

        return \Illuminate\Support\Facades\Cache::remember(
            "locations:districts:v{$ver}:{$stateId}",
            3600,
            fn () => \Illuminate\Support\Facades\DB::table('districts')
                ->where('is_active', true)
                ->when($stateId > 0, fn ($q) => $q->where('state_id', $stateId))
                ->orderBy('name')
                ->get(['id', 'state_id', 'name', 'code'])
                ->all()
        );
    }

    /**
     * Public: postal-code lookup (paginated) by district, city or free-text
     * pincode/office search — powers the coverage pickers.
     */
    public function postalCodes(Request $request)
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('postal_codes')) {
            return response()->json(['data' => []]);
        }
        $search = trim((string) $request->query('search', ''));

        return \Illuminate\Support\Facades\DB::table('postal_codes')
            ->where('status', 'active')
            ->when($request->filled('district_id'), fn ($q) => $q->where('district_id', (int) $request->district_id))
            ->when($request->filled('city_id'), fn ($q) => $q->where('city_id', (int) $request->city_id))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('pincode', 'like', $search . '%')
                ->orWhere('office_name', 'like', '%' . $search . '%')))
            ->orderBy('pincode')
            ->paginate(min(100, max(1, (int) ($request->limit ?? 50))));
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
        $city = City::create($data);
        $this->bustServiceAvailability();
        return $city;
    }

    public function cityUpdate(Request $request, $id)
    {
        $city = City::findOrFail($id);
        $city->update($this->validateCity($request, $city->id));
        $this->bustServiceAvailability();
        return $city->fresh('state');
    }

    public function cityDestroy($id)
    {
        $city = City::findOrFail($id);
        $city->delete();
        $this->bustServiceAvailability();
        return $city;
    }

    /**
     * Operations Control Center — a city's status/serviceability is Tier-2 of
     * the availability resolver (cached). Any city mutation must bump both the
     * availability map version AND the city-scoped product-list cache so the
     * change takes effect immediately (not after the 300s TTL).
     */
    protected function bustServiceAvailability(): void
    {
        app(\Marvel\Services\ServiceAvailabilityService::class)->bust();
        \Marvel\Services\AvailabilityService::bustCatalogCache();
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
        $this->bustServiceAvailability();
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

    // ── Admin: districts + postal-code remap (Delivery Coverage geo master) ─
    // Raw-table access (module-owned tables); geo:ver bump invalidates the
    // cached public lookups here and in the V2 geo endpoints.

    public function districtIndex(Request $request)
    {
        return \Illuminate\Support\Facades\DB::table('districts as d')
            ->leftJoin('states as s', 's.id', '=', 'd.state_id')
            ->when($request->filled('state_id'), fn ($q) => $q->where('d.state_id', (int) $request->state_id))
            ->when($request->filled('search'), fn ($q) => $q->where('d.name', 'like', "%{$request->search}%"))
            ->orderBy('d.name')
            ->select('d.*', 's.name as state_name')
            ->paginate((int) ($request->limit ?? 50));
    }

    public function districtStore(Request $request)
    {
        $data = $request->validate([
            'state_id'  => 'required|integer|exists:states,id',
            'name'      => 'required|string|max:255',
            'code'      => 'nullable|string|max:16',
            'is_active' => 'nullable|boolean',
        ]);
        $exists = \Illuminate\Support\Facades\DB::table('districts')
            ->where('state_id', $data['state_id'])
            ->whereRaw('LOWER(name) = ?', [strtolower($data['name'])])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'A district with this name already exists in the state.'], 422);
        }
        $now = now();
        $id = \Illuminate\Support\Facades\DB::table('districts')->insertGetId([
            'state_id'   => (int) $data['state_id'],
            'name'       => $data['name'],
            'code'       => $data['code'] ?? null,
            'is_active'  => (bool) ($data['is_active'] ?? true),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->bumpGeoVersion();
        return \Illuminate\Support\Facades\DB::table('districts')->where('id', $id)->first();
    }

    public function districtUpdate(Request $request, $id)
    {
        $table = \Illuminate\Support\Facades\DB::table('districts');
        $district = (clone $table)->where('id', (int) $id)->first();
        if (!$district) {
            return response()->json(['message' => 'District not found.'], 404);
        }
        $data = $request->validate([
            'state_id'  => 'sometimes|integer|exists:states,id',
            'name'      => 'sometimes|string|max:255',
            'code'      => 'nullable|string|max:16',
            'is_active' => 'nullable|boolean',
        ]);
        $update = array_intersect_key($data, array_flip(['state_id', 'name', 'code', 'is_active']));
        if ($update !== []) {
            (clone $table)->where('id', (int) $id)->update($update + ['updated_at' => now()]);
            $this->bumpGeoVersion();
        }
        return (clone $table)->where('id', (int) $id)->first();
    }

    /** PUT postal-codes/{id} — remap a pin's city (projection city bridge) or flip its status. */
    public function postalCodeUpdate(Request $request, $id)
    {
        $table = \Illuminate\Support\Facades\DB::table('postal_codes');
        $row = (clone $table)->where('id', (int) $id)->first();
        if (!$row) {
            return response()->json(['message' => 'Postal code not found.'], 404);
        }
        $data = $request->validate([
            'city_id' => 'nullable|integer|exists:cities,id',
            'status'  => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        $update = [];
        if ($request->exists('city_id')) {
            $update['city_id'] = $data['city_id'] ?? null;
        }
        if (array_key_exists('status', $data)) {
            $update['status'] = $data['status'];
        }
        if ($update !== []) {
            (clone $table)->where('id', (int) $id)->update($update + ['updated_at' => now()]);
            $this->bumpGeoVersion();
        }
        return (clone $table)->where('id', (int) $id)->first();
    }

    /** Invalidate cached geo lookups (public districts/postal endpoints key off geo:ver). */
    protected function bumpGeoVersion(): void
    {
        try {
            \Illuminate\Support\Facades\Cache::increment('geo:ver');
        } catch (\Throwable $e) {
            // cache driver hiccup — stale lookups expire via TTL
        }
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
