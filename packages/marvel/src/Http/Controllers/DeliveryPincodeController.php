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
    /**
     * Public: is a pincode serviceable? Two systems answer, OR-ed together:
     * the legacy allow-list (delivery_pincodes rows) and the vendor Delivery
     * Coverage projection (via CoverageBridge). `source` reports which side(s)
     * said yes; `available_vendors` = active vendors covering the pin. Fails
     * OPEN ("unconfigured") only when NEITHER system has any configuration.
     */
    public function check(Request $request)
    {
        $pincode = $this->normalize($request->get('pincode'));
        if (strlen($pincode) < 4) {
            return ['serviceable' => false, 'pincode' => $pincode, 'reason' => 'invalid'];
        }

        // Delivery Coverage side (fail-safe: any fault = "coverage not configured").
        $coverageConfigured = false;
        $coveredCount = 0;
        try {
            $service = \Marvel\Services\CoverageBridge::service();
            if ($service !== null && $service->anyCoverageConfigured()) {
                $coverageConfigured = true;
                $coveredCount = count($service->getAvailableNurseryIds($pincode));
            }
        } catch (\Throwable $e) {
            // coverage side unavailable — behave as before the coverage system existed
        }

        // Legacy allow-list side.
        $allowListConfigured = DeliveryPincode::where('is_active', true)->exists();
        $row = null;
        if ($allowListConfigured) {
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
        }

        // Fail OPEN only while BOTH systems are unconfigured: nothing to check
        // against, the feature is effectively off — never block checkout.
        if (!$allowListConfigured && !$coverageConfigured) {
            return ['serviceable' => true, 'pincode' => $pincode, 'unconfigured' => true, 'available_vendors' => 0, 'source' => null];
        }

        $coverageHit = $coveredCount > 0;
        $allowListHit = (bool) $row;

        // Observability: while both systems run side by side, log when they
        // disagree (throttled to one row per pincode per hour, shop_id 0).
        if ($coverageConfigured && $allowListConfigured && $coverageHit !== $allowListHit) {
            $this->logDivergence($pincode, $coverageHit, $allowListHit);
        }

        $source = match (true) {
            $coverageHit && $allowListHit => 'both',
            $coverageHit                  => 'coverage',
            $allowListHit                 => 'allowlist',
            default                       => null,
        };

        if (!$coverageHit && !$allowListHit) {
            return ['serviceable' => false, 'pincode' => $pincode, 'available_vendors' => 0, 'source' => null];
        }

        // Display fields: the allow-list row when hit; else enrich from the
        // postal master (state/district/city names — area has no equivalent).
        $area = $row->area ?? null;
        $cityName = $row->city ?? null;
        $stateName = $row->state ?? null;
        if (!$row && $coverageHit) {
            $geo = $this->postalGeo($pincode);
            $cityName = $geo['city'] ?? null;
            $stateName = $geo['state'] ?? null;
        }

        // City Activation Engine (Phase 2): a paused/disabled city overrides
        // BOTH systems; maintenance stays serviceable but is flagged. Only
        // applies when the city exists in the master table (else pincode-only).
        if ($cityName) {
            $city = \Marvel\Database\Models\City::where('name', $cityName)->first();
            if ($city && !$city->acceptsOrders()) {
                return [
                    'serviceable'       => false,
                    'pincode'           => $pincode,
                    'city'              => $cityName,
                    'state'             => $stateName,
                    'reason'            => 'city_' . $city->status, // city_paused | city_disabled
                    'available_vendors' => $coveredCount,
                    'source'            => $source,
                ];
            }
            $maintenance = $city && $city->status === \Marvel\Database\Models\City::STATUS_MAINTENANCE;
        }

        // Operations Control Center — optional per-vertical gate: a vertical
        // paused/disabled in this city overrides serviceability for that vertical.
        if ($request->filled('vertical') && $cityName) {
            $av = app(\Marvel\Services\ServiceAvailabilityService::class)->resolve((string) $request->vertical, $cityName);
            if (!$av['available']) {
                return [
                    'serviceable'       => false,
                    'pincode'           => $pincode,
                    'city'              => $cityName,
                    'state'             => $stateName,
                    'vertical'          => (string) $request->vertical,
                    'reason'            => $av['reason'],
                    'message'           => $av['message'],
                    'available_vendors' => $coveredCount,
                    'source'            => $source,
                ];
            }
        }

        return [
            'serviceable'       => true,
            'pincode'           => $row->pincode ?? $pincode,
            'area'              => $area,
            'city'              => $cityName,
            'state'             => $stateName,
            'cod_enabled'       => $row ? $row->cod_enabled : true,
            'eta_days'          => $row->eta_days ?? null,
            'maintenance'       => $maintenance ?? false,
            'available_vendors' => $coveredCount,
            'source'            => $source,
        ];
    }

    /** state/city display names for a pin from the postal master (best-effort). */
    private function postalGeo(string $pincode): array
    {
        try {
            $geo = \Illuminate\Support\Facades\DB::table('postal_codes')
                ->leftJoin('states', 'states.id', '=', 'postal_codes.state_id')
                ->leftJoin('districts', 'districts.id', '=', 'postal_codes.district_id')
                ->leftJoin('cities', 'cities.id', '=', 'postal_codes.city_id')
                ->where('postal_codes.pincode', $pincode)
                ->first([
                    'states.name as state_name',
                    'districts.name as district_name',
                    'cities.name as city_name',
                ]);
            if (!$geo) {
                return [];
            }
            return [
                'state'    => $geo->state_name,
                'district' => $geo->district_name,
                // Fall back to the district name for display when the pin has
                // no mapped master city (rural pins) — it is the locality name.
                'city'     => $geo->city_name ?? $geo->district_name,
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Audit a coverage↔allow-list disagreement (both configured, one yes one
     * no) — at most one row per pincode per hour. Best-effort; never throws.
     */
    private function logDivergence(string $pincode, bool $coverageHit, bool $allowListHit): void
    {
        try {
            $logs = \Illuminate\Support\Facades\DB::table('coverage_audit_logs');
            $recent = (clone $logs)
                ->where('action', 'divergence')
                ->where('created_at', '>=', now()->subHour())
                ->where('payload', 'like', '%"pincode":"' . $pincode . '"%')
                ->exists();
            if ($recent) {
                return;
            }
            $logs->insert([
                'shop_id'    => 0,
                'user_id'    => null,
                'action'     => 'divergence',
                'payload'    => json_encode(['pincode' => $pincode, 'coverage' => $coverageHit, 'allowlist' => $allowListHit]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // observability only — never break the public check
        }
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
