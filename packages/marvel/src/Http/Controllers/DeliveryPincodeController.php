<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Models\DeliveryPincode;

/**
 * Delivery serviceability by pincode (allow-list model).
 * Public: `check` (storefront serviceability check).
 * Admin (SUPER_ADMIN group): list / store / update / destroy / bulk import.
 */
class DeliveryPincodeController extends CoreController
{
    /** Public: is a pincode serviceable? */
    public function check(Request $request)
    {
        $pincode = $this->normalize($request->get('pincode'));
        if (strlen($pincode) < 4) {
            return ['serviceable' => false, 'pincode' => $pincode, 'reason' => 'invalid'];
        }
        // Fail OPEN until the allow-list is configured: if no serviceable pincodes
        // exist at all, the feature is effectively off — never block checkout.
        if (!DeliveryPincode::where('is_active', true)->exists()) {
            return ['serviceable' => true, 'pincode' => $pincode, 'unconfigured' => true];
        }
        // Match an exact pincode OR a range row [pincode .. pincode_end] that
        // contains it. Prefer an exact (single-pincode) row over a range.
        $pin = (int) $pincode;
        $row = DeliveryPincode::where('is_active', true)
            ->where(function ($q) use ($pincode, $pin) {
                $q->where('pincode', $pincode)
                    ->orWhere(function ($r) use ($pin) {
                        $r->whereNotNull('pincode_end')
                            ->whereRaw('CAST(pincode AS UNSIGNED) <= ?', [$pin])
                            ->whereRaw('CAST(pincode_end AS UNSIGNED) >= ?', [$pin]);
                    });
            })
            ->orderByRaw('pincode_end IS NULL DESC')
            ->first();

        if (!$row) {
            return ['serviceable' => false, 'pincode' => $pincode];
        }

        // City Activation Engine (Phase 2): a paused/disabled city overrides the
        // pincode allow-list; maintenance stays serviceable but is flagged. Only
        // applies when the city exists in the master table (else pincode-only).
        if ($row->city) {
            $city = \Marvel\Database\Models\City::where('name', $row->city)->first();
            if ($city && !$city->acceptsOrders()) {
                return [
                    'serviceable' => false,
                    'pincode'     => $row->pincode,
                    'city'        => $row->city,
                    'state'       => $row->state,
                    'reason'      => 'city_' . $city->status, // city_paused | city_disabled
                ];
            }
            $maintenance = $city && $city->status === \Marvel\Database\Models\City::STATUS_MAINTENANCE;
        }

        // Operations Control Center — optional per-vertical gate: a vertical
        // paused/disabled in this city overrides serviceability for that vertical.
        if ($request->filled('vertical') && $row->city) {
            $av = app(\Marvel\Services\ServiceAvailabilityService::class)->resolve((string) $request->vertical, $row->city);
            if (!$av['available']) {
                return [
                    'serviceable' => false,
                    'pincode'     => $row->pincode,
                    'city'        => $row->city,
                    'state'       => $row->state,
                    'vertical'    => (string) $request->vertical,
                    'reason'      => $av['reason'],
                    'message'     => $av['message'],
                ];
            }
        }

        return [
            'serviceable' => true,
            'pincode'     => $row->pincode,
            'area'        => $row->area,
            'city'        => $row->city,
            'state'       => $row->state,
            'cod_enabled' => $row->cod_enabled,
            'eta_days'    => $row->eta_days,
            'maintenance' => $maintenance ?? false,
        ];
    }

    /** Admin: paginated list (searchable by pincode/city/area). */
    public function index(Request $request)
    {
        $limit = (int) ($request->limit ?? 30);
        $search = $request->search ?? $request->pincode;

        $query = DeliveryPincode::query();
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('pincode', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('area', 'like', "%{$search}%");
            });
        }
        return $query->orderByDesc('id')->paginate($limit);
    }

    /** Admin: create (or upsert by pincode). */
    public function store(Request $request)
    {
        $data = $this->payload($request);
        if (strlen($data['pincode']) < 4) {
            return response()->json(['message' => 'A valid pincode is required.'], 422);
        }
        return DeliveryPincode::updateOrCreate(['pincode' => $data['pincode']], $data);
    }

    /** Admin: update. */
    public function update(Request $request, $id)
    {
        $row = DeliveryPincode::findOrFail($id);
        $row->update($this->payload($request));
        return $row;
    }

    /** Admin: delete. */
    public function destroy($id)
    {
        $row = DeliveryPincode::findOrFail($id);
        $row->delete();
        return $row;
    }

    /** Admin: bulk import — `pincodes` (array) or `text` (comma/newline list). */
    public function bulkStore(Request $request)
    {
        $raw = $request->input('pincodes', $request->input('text', ''));
        $list = is_array($raw) ? $raw : preg_split('/[\s,]+/', (string) $raw);
        $city = $request->input('city');
        $state = $request->input('state');

        $imported = 0;
        foreach ((array) $list as $p) {
            $pincode = $this->normalize($p);
            if (strlen($pincode) < 4) {
                continue;
            }
            DeliveryPincode::updateOrCreate(
                ['pincode' => $pincode],
                ['is_active' => true, 'city' => $city, 'state' => $state]
            );
            $imported++;
        }
        return ['imported' => $imported];
    }

    private function payload(Request $request): array
    {
        $data = $request->only([
            'pincode', 'pincode_end', 'area', 'city', 'state', 'is_active', 'cod_enabled', 'eta_days',
        ]);
        $data['pincode'] = $this->normalize($data['pincode'] ?? '');
        // Optional range end — normalised, or null for a single-pincode row.
        $end = $this->normalize($data['pincode_end'] ?? '');
        $data['pincode_end'] = strlen($end) >= 4 ? $end : null;
        return $data;
    }

    private function normalize($value): string
    {
        return preg_replace('/\D/', '', (string) $value);
    }
}
