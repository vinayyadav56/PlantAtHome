<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Enums\Permission;

/**
 * Delivery Coverage — pincode-level vendor coverage rules over the geo master
 * (states → districts → cities → postal codes), projected by the Serviceability
 * module into vendor_covered_pincodes. This marvel controller is a thin HTTP
 * layer: ALL domain writes go through the V2 DeliveryCoverageService resolved
 * via CoverageBridge (503 when the module is unavailable); list reads use the
 * raw tables so no V2 class is ever named here.
 *
 * Admin (SUPER_ADMIN group): index / summary / pincodes / store / preview /
 * destroy / sync / import / export / audit.
 * Vendor (STORE_OWNER group, own shop or super admin): myCoverage / mySummary /
 * mySyncRules / myPreview.
 */
class DeliveryCoverageController extends CoreController
{
    private const RULE_TYPES = ['state', 'district', 'city', 'pincode_include', 'pincode_exclude'];

    /** The V2 coverage service, or a 503 that tells ops exactly what is missing. */
    private function service()
    {
        $service = \Marvel\Services\CoverageBridge::service();
        if ($service === null) {
            abort(503, 'Delivery Coverage service is unavailable.');
        }
        return $service;
    }

    /**
     * A use-case failure thrown by the V2 service (duck-typed so the V2 class
     * is never named here) → marvel-style JSON error; anything else rethrows.
     */
    private function domainError(\Throwable $e)
    {
        if (method_exists($e, 'errorCode') && method_exists($e, 'httpStatus')) {
            return response()->json(
                ['message' => $e->getMessage(), 'code' => $e->errorCode(), 'field' => method_exists($e, 'field') ? $e->field() : null],
                $e->httpStatus()
            );
        }
        throw $e;
    }

    /* ── Admin ─────────────────────────────────────────────────────────── */

    /** GET coverage — rules list (filters: shop_id, rule_type, is_active, search), paginated. */
    public function index(Request $request)
    {
        return $this->rulesQuery($request)->paginate((int) ($request->limit ?? 30));
    }

    /** GET coverage/summary?shop_id= — grouped rules + projection totals for one vendor. */
    public function summary(Request $request)
    {
        $request->validate(['shop_id' => 'required|integer|min:1']);
        return $this->service()->getCoverageSummary((int) $request->shop_id);
    }

    /** GET coverage/pincodes?shop_id= — the flattened covered-pincode projection, paginated. */
    public function pincodes(Request $request)
    {
        $request->validate(['shop_id' => 'required|integer|min:1']);
        return $this->service()->getCoveredPincodes(
            (int) $request->shop_id,
            (int) ($request->limit ?? 100),
            (int) ($request->page ?? 1)
        );
    }

    /** POST coverage {shop_id, rule_type, state_id|district_id|city_id|pincode} — add one rule + re-project. */
    public function store(Request $request)
    {
        $request->validate([
            'shop_id'   => 'required|integer|min:1',
            'rule_type' => 'required|string',
        ]);
        try {
            return $this->service()->addCoverage(
                (int) $request->shop_id,
                (string) $request->rule_type,
                $request->only(['state_id', 'district_id', 'city_id', 'pincode']),
                $request->user()?->id
            );
        } catch (\Throwable $e) {
            return $this->domainError($e);
        }
    }

    /** POST coverage/preview {rules:[...]} — dry-run the projection ladder, no writes. */
    public function preview(Request $request)
    {
        $request->validate(['rules' => 'required|array']);
        try {
            return $this->service()->previewCoverage((array) $request->input('rules'));
        } catch (\Throwable $e) {
            return $this->domainError($e);
        }
    }

    /** DELETE coverage/{id} — remove a rule (shop resolved from the rule) + re-project. */
    public function destroy(Request $request, $id)
    {
        $rule = DB::table('vendor_coverage_rules')->where('id', (int) $id)->first();
        if (!$rule) {
            return response()->json(['message' => 'Coverage rule not found.'], 404);
        }
        try {
            $this->service()->removeCoverage((int) $rule->shop_id, (int) $id, $request->user()?->id);
        } catch (\Throwable $e) {
            return $this->domainError($e);
        }
        return (array) $rule;
    }

    /** POST coverage/{shop_id}/sync — force a re-projection for one vendor. */
    public function sync(Request $request, $shop_id)
    {
        return $this->service()->syncCoverage((int) $shop_id);
    }

    /**
     * POST coverage/import — CSV upload (field `csv` or `file`) with columns
     * shop,rule_type,state,district,city,pincode,active. Shop resolves by id or
     * slug; state/district/city by (case-insensitive) name. Rows fail
     * individually; the response is {imported, failed, errors:[{row,message}]}.
     */
    public function import(Request $request)
    {
        $file = $request->file('csv') ?? $request->file('file');
        if (!$file || !$file->isValid()) {
            return response()->json(['message' => 'A CSV file is required (field `csv` or `file`).'], 422);
        }

        $service = $this->service();
        $actorId = $request->user()?->id;

        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) {
            return response()->json(['message' => 'Could not read the uploaded file.'], 422);
        }

        $header = fgetcsv($handle);
        $header = is_array($header) ? array_map(fn ($h) => strtolower(trim((string) $h)), $header) : [];
        $required = ['shop', 'rule_type'];
        if (count(array_intersect($required, $header)) !== count($required)) {
            fclose($handle);
            return response()->json(['message' => 'CSV header must include: shop, rule_type (plus state/district/city/pincode/active).'], 422);
        }

        $imported = 0;
        $failed = 0;
        $errors = [];
        $needsResync = []; // shops whose imported rules were flagged inactive
        $rowNo = 1;

        while (($raw = fgetcsv($handle)) !== false) {
            $rowNo++;
            if ($raw === [null] || $raw === []) {
                continue; // blank line
            }
            $row = [];
            foreach ($header as $i => $col) {
                $row[$col] = isset($raw[$i]) ? trim((string) $raw[$i]) : '';
            }

            try {
                $shopId = $this->resolveShopRef($row['shop'] ?? '');
                $ruleType = strtolower($row['rule_type'] ?? '');
                if (!in_array($ruleType, self::RULE_TYPES, true)) {
                    throw new \InvalidArgumentException("Unknown rule_type '{$row['rule_type']}'.");
                }
                $target = $this->resolveTarget($ruleType, $row);

                $rule = $service->addCoverage($shopId, $ruleType, $target, $actorId);

                $active = $row['active'] ?? '';
                if ($active !== '' && in_array(strtolower($active), ['0', 'false', 'no', 'inactive'], true)) {
                    DB::table('vendor_coverage_rules')->where('id', $rule->id)->update(['is_active' => false]);
                    $needsResync[$shopId] = true;
                }
                $imported++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['row' => $rowNo, 'message' => $e->getMessage()];
            }
        }
        fclose($handle);

        // Rules flipped inactive after addCoverage's sync need one more projection pass.
        foreach (array_keys($needsResync) as $shopId) {
            try {
                $service->syncCoverage((int) $shopId);
            } catch (\Throwable $e) {
                $errors[] = ['row' => 0, 'message' => "Re-sync failed for shop {$shopId}: {$e->getMessage()}"];
            }
        }

        return ['imported' => $imported, 'failed' => $failed, 'errors' => $errors];
    }

    /** GET coverage/export?shop_id= — rules as a re-importable streamed CSV. */
    public function export(Request $request)
    {
        $rows = $this->rulesQuery($request)->orderBy('r.shop_id')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['shop', 'rule_type', 'state', 'district', 'city', 'pincode', 'active']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->shop_slug ?: $row->shop_id,
                    $row->rule_type,
                    $row->state_name,
                    $row->district_name,
                    $row->city_name,
                    $row->pincode,
                    $row->is_active ? 1 : 0,
                ]);
            }
            fclose($out);
        }, 'coverage-rules.csv', ['Content-Type' => 'text/csv']);
    }

    /** GET coverage/audit?shop_id= — coverage audit trail, paginated (payload decoded). */
    public function audit(Request $request)
    {
        return DB::table('coverage_audit_logs')
            ->when($request->filled('shop_id'), fn ($q) => $q->where('shop_id', (int) $request->shop_id))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->action))
            ->orderByDesc('id')
            ->paginate((int) ($request->limit ?? 30))
            ->through(fn ($row) => [
                'id'         => (int) $row->id,
                'shop_id'    => (int) $row->shop_id,
                'user_id'    => $row->user_id !== null ? (int) $row->user_id : null,
                'action'     => $row->action,
                'payload'    => $row->payload !== null ? json_decode($row->payload, true) : null,
                'created_at' => $row->created_at,
            ]);
    }

    /* ── Vendor self-serve (own shop, or super admin on any shop) ─────────── */

    /** GET my-coverage/{shop_id} — the vendor's own rules, paginated. */
    public function myCoverage(Request $request, $shop_id)
    {
        $shopId = $this->assertOwnsShop($request, (int) $shop_id);
        $request->merge(['shop_id' => $shopId]);
        return $this->rulesQuery($request)->paginate((int) ($request->limit ?? 100));
    }

    /** GET my-coverage/{shop_id}/summary */
    public function mySummary(Request $request, $shop_id)
    {
        $shopId = $this->assertOwnsShop($request, (int) $shop_id);
        return $this->service()->getCoverageSummary($shopId);
    }

    /** PUT my-coverage/{shop_id}/rules {rules:[...]} — replace-all rule set + one re-projection. */
    public function mySyncRules(Request $request, $shop_id)
    {
        $shopId = $this->assertOwnsShop($request, (int) $shop_id);
        $request->validate(['rules' => 'present|array']);
        try {
            $stats = $this->service()->syncRules($shopId, (array) $request->input('rules'), $request->user()?->id);
        } catch (\Throwable $e) {
            return $this->domainError($e);
        }
        return ['shop_id' => $shopId, 'stats' => $stats];
    }

    /** POST my-coverage/{shop_id}/preview {rules:[...]} */
    public function myPreview(Request $request, $shop_id)
    {
        $this->assertOwnsShop($request, (int) $shop_id);
        $request->validate(['rules' => 'required|array']);
        try {
            return $this->service()->previewCoverage((array) $request->input('rules'));
        } catch (\Throwable $e) {
            return $this->domainError($e);
        }
    }

    /* ── internals ─────────────────────────────────────────────────────── */

    /**
     * A vendor may only act on a shop they own; a super admin may act on any
     * shop (same ownership rule as the vendor/service-areas endpoints).
     */
    private function assertOwnsShop(Request $request, int $shopId): int
    {
        if ($shopId <= 0) {
            abort(422, 'A valid shop_id is required.');
        }
        $user = $request->user();
        $shops = $user ? $user->shops : collect();
        $isAdmin = $user && $user->hasPermissionTo(Permission::SUPER_ADMIN);
        if (!$isAdmin && !$shops->contains('id', $shopId)) {
            abort(403, 'You do not own this vendor shop.');
        }
        return $shopId;
    }

    /** Filterable rules query with target names resolved (raw tables — no V2 classes). */
    private function rulesQuery(Request $request)
    {
        return DB::table('vendor_coverage_rules as r')
            ->leftJoin('states as s', 's.id', '=', 'r.state_id')
            ->leftJoin('districts as d', 'd.id', '=', 'r.district_id')
            ->leftJoin('cities as c', 'c.id', '=', 'r.city_id')
            ->leftJoin('shops as sh', 'sh.id', '=', 'r.shop_id')
            ->when($request->filled('shop_id'), fn ($q) => $q->where('r.shop_id', (int) $request->shop_id))
            ->when($request->filled('rule_type'), fn ($q) => $q->where('r.rule_type', $request->rule_type))
            ->when($request->filled('is_active'), fn ($q) => $q->where('r.is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = trim((string) $request->search);
                $q->where(function ($w) use ($term) {
                    $w->where('r.pincode', 'like', "%{$term}%")
                        ->orWhere('r.target_key', 'like', "%{$term}%")
                        ->orWhere('s.name', 'like', "%{$term}%")
                        ->orWhere('d.name', 'like', "%{$term}%")
                        ->orWhere('c.name', 'like', "%{$term}%")
                        ->orWhere('sh.name', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('r.id')
            ->select(
                'r.*',
                's.name as state_name',
                'd.name as district_name',
                'c.name as city_name',
                'sh.name as shop_name',
                'sh.slug as shop_slug'
            );
    }

    /** Shop reference from CSV: numeric id or slug. */
    private function resolveShopRef(string $ref): int
    {
        $ref = trim($ref);
        if ($ref === '') {
            throw new \InvalidArgumentException('Missing shop (id or slug).');
        }
        if (ctype_digit($ref)) {
            $id = DB::table('shops')->where('id', (int) $ref)->value('id');
            if ($id !== null) {
                return (int) $id;
            }
        }
        $id = DB::table('shops')->whereRaw('LOWER(slug) = ?', [strtolower($ref)])->value('id');
        if ($id === null) {
            throw new \InvalidArgumentException("Shop '{$ref}' not found (by id or slug).");
        }
        return (int) $id;
    }

    /** CSV row → the target payload addCoverage expects, resolving names to ids. */
    private function resolveTarget(string $ruleType, array $row): array
    {
        $stateId = null;
        if (($row['state'] ?? '') !== '') {
            $stateId = DB::table('states')->whereRaw('LOWER(name) = ?', [strtolower($row['state'])])->value('id');
            if ($stateId === null) {
                throw new \InvalidArgumentException("State '{$row['state']}' not found.");
            }
        }

        switch ($ruleType) {
            case 'state':
                if ($stateId === null) {
                    throw new \InvalidArgumentException('A state name is required for a state rule.');
                }
                return ['state_id' => (int) $stateId];

            case 'district':
                if (($row['district'] ?? '') === '') {
                    throw new \InvalidArgumentException('A district name is required for a district rule.');
                }
                $id = DB::table('districts')
                    ->whereRaw('LOWER(name) = ?', [strtolower($row['district'])])
                    ->when($stateId !== null, fn ($q) => $q->where('state_id', $stateId))
                    ->value('id');
                if ($id === null) {
                    throw new \InvalidArgumentException("District '{$row['district']}' not found.");
                }
                return ['district_id' => (int) $id];

            case 'city':
                if (($row['city'] ?? '') === '') {
                    throw new \InvalidArgumentException('A city name is required for a city rule.');
                }
                $id = DB::table('cities')
                    ->whereRaw('LOWER(name) = ?', [strtolower($row['city'])])
                    ->when($stateId !== null, fn ($q) => $q->where('state_id', $stateId))
                    ->value('id');
                if ($id === null) {
                    throw new \InvalidArgumentException("City '{$row['city']}' not found.");
                }
                return ['city_id' => (int) $id];

            default: // pincode_include | pincode_exclude
                if (($row['pincode'] ?? '') === '') {
                    throw new \InvalidArgumentException('A pincode is required for a pincode rule.');
                }
                return ['pincode' => $row['pincode']];
        }
    }
}
