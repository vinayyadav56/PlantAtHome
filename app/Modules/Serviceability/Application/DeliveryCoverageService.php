<?php

namespace App\Modules\Serviceability\Application;

use App\Modules\Serviceability\Domain\Events\VendorCoverageChanged;
use App\Modules\Serviceability\Infrastructure\Models\CoverageAuditLog;
use App\Modules\Serviceability\Infrastructure\Models\VendorCoveredPincode;
use App\Modules\Serviceability\Infrastructure\Models\VendorCoverageRule;
use App\Shared\Application\DomainActionException;
use App\Shared\Events\EventPublisher;
use App\Shared\Infrastructure\Backfill\LegacyUuid;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Pincode-level delivery coverage over the legacy vendor (`shops`) model:
 * vendors declare rules (state / district / city / include / exclude pins),
 * CoverageProjector flattens them into vendor_covered_pincodes, and this
 * service answers "who delivers to this pincode?" from that projection.
 * Reads are cached under a bumped version key (coverage:ver) — the versioned
 * pattern the marvel catalog cache uses. NO fail-open here: invalid input
 * throws DomainActionException; callers decide their own degradation policy.
 */
class DeliveryCoverageService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CoverageProjector $projector,
        private readonly EventPublisher $events,
    ) {
    }

    /* ── reads ─────────────────────────────────────────────────────────── */

    public function isDeliverable(int $shopId, string $pincode): bool
    {
        $pin = $this->normalizePincode($pincode);
        if ($pin === '') {
            return false;
        }

        return VendorCoveredPincode::where('shop_id', $shopId)->where('pincode', $pin)->exists();
    }

    /**
     * Active vendors covering a pincode (the storefront hot path). Joins the
     * legacy shops table for is_active when it exists — the isolated test DBs
     * that skip legacy tables just read the raw projection.
     *
     * @return int[] shop ids
     */
    public function getAvailableNurseryIds(string $pincode): array
    {
        $pin = $this->normalizePincode($pincode);
        if ($pin === '') {
            return [];
        }

        return Cache::remember("coverage:v{$this->version()}:pin:{$pin}", 3600, function () use ($pin) {
            $query = $this->db->table('vendor_covered_pincodes')->where('pincode', $pin);
            if ($this->db->getSchemaBuilder()->hasTable('shops')) {
                $query->join('shops', 'shops.id', '=', 'vendor_covered_pincodes.shop_id')
                    ->where('shops.is_active', 1);
            }

            return $query->distinct()->orderBy('vendor_covered_pincodes.shop_id')
                ->pluck('vendor_covered_pincodes.shop_id')
                ->map(fn ($id) => (int) $id)->values()->all();
        });
    }

    /**
     * Projection rows for a pincode (admin/diagnostic detail — no shop join).
     *
     * @return array<int, array{shop_id:int, source:string, state_id:?int, district_id:?int, city_id:?int}>
     */
    public function getAvailableNurseries(string $pincode): array
    {
        $pin = $this->normalizePincode($pincode);
        if ($pin === '') {
            return [];
        }

        return VendorCoveredPincode::where('pincode', $pin)->orderBy('shop_id')
            ->get(['shop_id', 'source', 'state_id', 'district_id', 'city_id'])
            ->map(fn (VendorCoveredPincode $row) => [
                'shop_id'     => (int) $row->shop_id,
                'source'      => $row->source,
                'state_id'    => $row->state_id !== null ? (int) $row->state_id : null,
                'district_id' => $row->district_id !== null ? (int) $row->district_id : null,
                'city_id'     => $row->city_id !== null ? (int) $row->city_id : null,
            ])->all();
    }

    public function getCoveredPincodes(int $shopId, int $perPage = 100, int $page = 1): LengthAwarePaginator
    {
        return VendorCoveredPincode::where('shop_id', $shopId)
            ->orderBy('pincode')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @return array{rules:array, totals:array, manual_added:string[], manual_removed:string[], last_synced:?string}
     */
    public function getCoverageSummary(int $shopId): array
    {
        $rules = VendorCoverageRule::where('shop_id', $shopId)->orderBy('id')->get();

        $names = [
            'state'    => $this->namesById('states', $rules->pluck('state_id')),
            'district' => $this->namesById('districts', $rules->pluck('district_id')),
            'city'     => $this->namesById('cities', $rules->pluck('city_id')),
        ];

        $grouped = [];
        foreach ($rules as $rule) {
            $grouped[$rule->rule_type][] = [
                'id'        => $rule->id,
                'target_key' => $rule->target_key,
                'target'    => match ($rule->rule_type) {
                    VendorCoverageRule::TYPE_STATE    => $names['state'][$rule->state_id] ?? null,
                    VendorCoverageRule::TYPE_DISTRICT => $names['district'][$rule->district_id] ?? null,
                    VendorCoverageRule::TYPE_CITY     => $names['city'][$rule->city_id] ?? null,
                    default                           => $rule->pincode,
                },
                'is_active' => (bool) $rule->is_active,
            ];
        }

        $projection = $this->db->table('vendor_covered_pincodes')->where('shop_id', $shopId);
        $bySource = (clone $projection)->selectRaw('source, COUNT(*) as n')->groupBy('source')
            ->pluck('n', 'source')->map(fn ($n) => (int) $n)->all();

        $activePins = fn (string $type) => $rules
            ->where('rule_type', $type)->where('is_active', true)
            ->pluck('pincode')->values()->all();

        return [
            'rules'  => $grouped,
            'totals' => [
                'covered_pincodes'  => (clone $projection)->count(),
                'by_source'         => $bySource,
                'states_covered'    => (clone $projection)->whereNotNull('state_id')->distinct()->count('state_id'),
                'districts_covered' => (clone $projection)->whereNotNull('district_id')->distinct()->count('district_id'),
            ],
            'manual_added'   => $activePins(VendorCoverageRule::TYPE_PINCODE_INCLUDE),
            'manual_removed' => $activePins(VendorCoverageRule::TYPE_PINCODE_EXCLUDE),
            'last_synced'    => CoverageAuditLog::where('shop_id', $shopId)
                ->whereIn('action', ['rule_added', 'rule_removed', 'sync'])
                ->max('created_at'),
        ];
    }

    /** The winning rule tier for a covered pin (state|district|city|manual), null when uncovered. */
    public function resolveSource(int $shopId, string $pincode): ?string
    {
        $pin = $this->normalizePincode($pincode);
        if ($pin === '') {
            return null;
        }

        return VendorCoveredPincode::where('shop_id', $shopId)->where('pincode', $pin)->value('source');
    }

    /** Platform-wide gate: does ANY vendor have an active coverage rule yet? */
    public function anyCoverageConfigured(): bool
    {
        return (bool) Cache::remember(
            "coverage:v{$this->version()}:configured",
            300,
            fn () => VendorCoverageRule::where('is_active', true)->exists(),
        );
    }

    /**
     * Per-scope gate for the public pincode check: is coverage configured for
     * the STATE this pin sits in? One vendor's first rule in Karnataka must not
     * turn every Haryana pin non-serviceable. Delegates to the one resolver so
     * there is a single definition of "configured".
     */
    public function coverageConfiguredFor(string $pincode, ?string $vertical = null): bool
    {
        return app(VendorServiceabilityResolver::class)->configuredFor($pincode, $vertical);
    }

    /**
     * Dry-run the projection ladder over a candidate rule set — no writes.
     * With a parent node, also returns one entry per CHILD of that parent with
     * its covered/excluded counts and a none|partial|all state: that is what
     * draws the tri-state tree, so the admin never has to load pincodes.
     *
     * @param  array<int, array>  $rules
     * @return array{total:int, excluded:int, by_source:array<string,int>, cities_covered:int, sample:string[], nodes:array}
     */
    public function previewCoverage(array $rules, ?string $vertical = null, ?string $parentType = null, ?int $parentId = null): array
    {
        $vertical = $vertical === null ? null : VendorCoverageRule::normalizeVertical($vertical);

        $normalized = [];
        foreach ($rules as $rule) {
            $attrs = $this->validatedRuleAttributes((string) ($rule['rule_type'] ?? ''), is_array($rule) ? $rule : []);
            // A named vertical projects from its own rules alone; the '*' rules
            // are what answers every vertical the vendor never named.
            if ($vertical === null || $attrs['vertical'] === $vertical) {
                $normalized[] = $attrs;
            }
        }

        [$map] = $this->projector->computeMap($normalized);

        $bySource = [];
        $cities = [];
        foreach ($map as $hit) {
            $bySource[$hit['source']] = ($bySource[$hit['source']] ?? 0) + 1;
            if ($hit['city_id'] !== null) {
                $cities[$hit['city_id']] = true;
            }
        }
        $pins = array_map('strval', array_keys($map));
        sort($pins);

        return [
            'total'          => count($map),
            'by_source'      => $bySource,
            'cities_covered' => count($cities),
            'sample'         => array_slice($pins, 0, 10),
        ] + $this->nodeCounts($map, $parentType, $parentId);
    }

    /**
     * Child-by-child coverage under one parent node, for the tri-state tree.
     * `excluded` = pins in scope the rules did NOT cover, so a partially
     * covered district reads "12 of 23" without shipping a pincode list.
     *
     * @param  array<string, array>  $map
     * @return array{excluded:int, nodes:array<int, array>}
     */
    private function nodeCounts(array $map, ?string $parentType, ?int $parentId): array
    {
        $scope = $this->db->table('postal_codes')->where('status', 'active');
        [$groupBy, $joinTable] = match ($parentType) {
            'root'     => ['state_id', 'states'],
            'state'    => ['district_id', 'districts'],
            'district' => ['city_id', 'cities'],
            default    => [null, null],
        };
        if ($groupBy === null) {
            return ['excluded' => 0, 'nodes' => []];
        }
        if ($parentType === 'state') {
            $scope->where('state_id', $parentId);
        } elseif ($parentType === 'district') {
            $scope->where('district_id', $parentId);
        }

        $totals = $scope->selectRaw("{$groupBy} as node_id, COUNT(*) as n")
            ->groupBy($groupBy)->pluck('n', 'node_id')->all();

        $covered = [];
        foreach ($map as $hit) {
            $id = $hit[$groupBy] ?? null;
            $covered[$id === null ? '' : (int) $id] = ($covered[$id === null ? '' : (int) $id] ?? 0) + 1;
        }

        $names = $this->namesById($joinTable, array_filter(array_keys($totals), fn ($k) => $k !== null && $k !== ''));
        $childType = ['state_id' => 'state', 'district_id' => 'district', 'city_id' => 'city'][$groupBy];

        $nodes = [];
        $excluded = 0;
        foreach ($totals as $id => $total) {
            $total = (int) $total;
            $key = $id === null || $id === '' ? '' : (int) $id;
            $hits = (int) ($covered[$key] ?? 0);
            $excluded += $total - $hits;
            $nodes[] = [
                // A null id is the real "pins with no city yet" bucket: two
                // thirds of master cities were never rolled up, so hiding it
                // would make a district look smaller than it is.
                'type'     => $key === '' ? $childType.'_unassigned' : $childType,
                'id'       => $key === '' ? null : $key,
                'name'     => $key === '' ? 'Not assigned' : ($names[$key] ?? ('#'.$key)),
                'total'    => $total,
                'covered'  => $hits,
                'excluded' => $total - $hits,
                'state'    => $hits === 0 ? 'none' : ($hits >= $total ? 'all' : 'partial'),
            ];
        }
        usort($nodes, fn ($a, $b) => [$a['id'] === null, $a['name']] <=> [$b['id'] === null, $b['name']]);

        return ['excluded' => $excluded, 'nodes' => $nodes];
    }

    /* ── writes ────────────────────────────────────────────────────────── */

    /**
     * Upsert one rule ($target: state_id | district_id | city_id | pincode),
     * re-project, audit. Include pins must exist in the postal master;
     * excludes only need a valid 6-digit format (excluding a pin we do not
     * know about is harmless and future-proof).
     */
    public function addCoverage(int $shopId, string $ruleType, array $target, ?int $actorId = null): VendorCoverageRule
    {
        $attrs = $this->validatedRuleAttributes($ruleType, $target);

        return $this->db->transaction(function () use ($shopId, $attrs, $actorId) {
            $rule = VendorCoverageRule::updateOrCreate(
                ['shop_id' => $shopId, 'target_key' => $attrs['target_key']],
                $attrs + ['is_active' => true, 'created_by' => $actorId],
            );

            $stats = $this->syncCoverage($shopId);
            $this->audit($shopId, $actorId, 'rule_added', ['rule_id' => $rule->id, 'target_key' => $rule->target_key, 'stats' => $stats]);

            return $rule;
        });
    }

    public function removeCoverage(int $shopId, int $ruleId, ?int $actorId = null): void
    {
        $rule = VendorCoverageRule::where('shop_id', $shopId)->where('id', $ruleId)->first();
        if (! $rule) {
            throw DomainActionException::notFound('Coverage rule not found for this vendor.', 'COVERAGE_RULE_NOT_FOUND');
        }

        $this->db->transaction(function () use ($shopId, $rule, $actorId) {
            $targetKey = $rule->target_key;
            $rule->delete();

            $stats = $this->syncCoverage($shopId);
            $this->audit($shopId, $actorId, 'rule_removed', ['rule_id' => $rule->id, 'target_key' => $targetKey, 'stats' => $stats]);
        });
    }

    /**
     * Replace-all: the payload becomes the vendor's exact rule set (rules not
     * present are deleted, the rest upserted), then ONE projection sync.
     *
     * @param  array<int, array>  $rules  each: {rule_type, state_id|district_id|city_id|pincode, is_active?}
     * @return array projection stats
     */
    public function syncRules(int $shopId, array $rules, ?int $actorId = null): array
    {
        $prepared = [];
        foreach ($rules as $rule) {
            $attrs = $this->validatedRuleAttributes((string) ($rule['rule_type'] ?? ''), is_array($rule) ? $rule : []);
            $attrs['is_active'] = (bool) ($rule['is_active'] ?? true);
            $prepared[$attrs['target_key']] = $attrs; // last occurrence wins
        }

        return $this->db->transaction(function () use ($shopId, $prepared, $actorId) {
            VendorCoverageRule::where('shop_id', $shopId)
                ->when($prepared !== [], fn ($q) => $q->whereNotIn('target_key', array_keys($prepared)))
                ->delete();

            foreach ($prepared as $targetKey => $attrs) {
                VendorCoverageRule::updateOrCreate(
                    ['shop_id' => $shopId, 'target_key' => $targetKey],
                    $attrs + ['created_by' => $actorId],
                );
            }

            $stats = $this->syncCoverage($shopId);
            $this->audit($shopId, $actorId, 'sync', ['rules' => count($prepared), 'stats' => $stats]);

            return $stats;
        });
    }

    /**
     * Re-project one vendor: rewrite vendor_covered_pincodes (+ legacy bridge),
     * bump the coverage cache version, and emit VendorCoverageChanged. The
     * event's nursery id is the deterministic v2 uuid for the legacy shop row;
     * '*' = city scope not applicable (coverage is pincode-wide).
     */
    public function syncCoverage(int $shopId): array
    {
        $stats = $this->projector->project($shopId);

        Cache::increment('coverage:ver');
        $this->events->publish(new VendorCoverageChanged(LegacyUuid::for('shops', $shopId), '*', true));

        return $stats;
    }

    /* ── internals ─────────────────────────────────────────────────────── */

    /**
     * Validate a (rule_type, target) pair and return the rule attributes incl.
     * target_key. Referenced geo rows must exist; include pins must exist in
     * postal_codes (else the rule could never project). Excludes are lenient:
     * excluding something we do not know about is harmless and future-proof.
     *
     * @return array{rule_type:string, vertical:string, state_id:?int, district_id:?int, city_id:?int, pincode:?string, fulfillment_mode:?string, eta_days:?int, target_key:string}
     */
    private function validatedRuleAttributes(string $ruleType, array $target): array
    {
        if (! in_array($ruleType, VendorCoverageRule::RULE_TYPES, true)) {
            throw DomainActionException::unprocessable('Unknown coverage rule type.', 'INVALID_RULE_TYPE', 'rule_type');
        }

        $vertical = VendorCoverageRule::normalizeVertical($target['vertical'] ?? null);

        $mode = $target['fulfillment_mode'] ?? null;
        if ($mode !== null && $mode !== '' && ! in_array($mode, VendorCoverageRule::MODES, true)) {
            throw DomainActionException::unprocessable('Unknown fulfilment mode.', 'INVALID_MODE', 'fulfillment_mode');
        }
        $eta = $target['eta_days'] ?? null;

        $attrs = [
            'rule_type'        => $ruleType,
            'vertical'         => $vertical,
            'state_id'         => null,
            'district_id'      => null,
            'city_id'          => null,
            'pincode'          => null,
            'fulfillment_mode' => $mode === '' ? null : $mode,
            'eta_days'         => $eta === null || $eta === '' ? null : (int) $eta,
        ];

        if (in_array($ruleType, [VendorCoverageRule::TYPE_PINCODE_INCLUDE, VendorCoverageRule::TYPE_PINCODE_EXCLUDE], true)) {
            $pin = $this->normalizePincode((string) ($target['pincode'] ?? ''));
            if (! preg_match('/^\d{6}$/', $pin)) {
                throw DomainActionException::unprocessable('Pincode must be 6 digits.', 'INVALID_PINCODE', 'pincode');
            }
            if ($ruleType === VendorCoverageRule::TYPE_PINCODE_INCLUDE
                && ! $this->db->table('postal_codes')->where('pincode', $pin)->exists()) {
                throw DomainActionException::unprocessable("Pincode {$pin} is not in the postal master.", 'PINCODE_UNKNOWN', 'pincode');
            }
            $attrs['pincode'] = $pin;
            $attrs['target_key'] = VendorCoverageRule::targetKey($ruleType, $pin, $vertical);

            return $attrs;
        }

        // state | district | city | district_exclude | city_exclude
        $column = match ($ruleType) {
            VendorCoverageRule::TYPE_STATE            => 'state_id',
            VendorCoverageRule::TYPE_DISTRICT,
            VendorCoverageRule::TYPE_DISTRICT_EXCLUDE => 'district_id',
            default                                   => 'city_id',
        };
        $id = (int) ($target[$column] ?? 0);
        $table = ['state_id' => 'states', 'district_id' => 'districts', 'city_id' => 'cities'][$column];
        if ($id <= 0 || ! $this->db->table($table)->where('id', $id)->exists()) {
            throw DomainActionException::unprocessable(ucfirst(str_replace('_', ' ', $ruleType)).' not found.', 'TARGET_NOT_FOUND', $column);
        }
        $attrs[$column] = $id;
        $attrs['target_key'] = VendorCoverageRule::targetKey($ruleType, $id, $vertical);

        return $attrs;
    }

    private function audit(int $shopId, ?int $actorId, string $action, array $payload): void
    {
        CoverageAuditLog::create([
            'shop_id' => $shopId,
            'user_id' => $actorId,
            'action'  => $action,
            'payload' => $payload,
        ]);
    }

    /** @return array<int,string> id => name (empty when the lookup table is absent) */
    private function namesById(string $table, $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values();
        if ($ids->isEmpty() || ! $this->db->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        return $this->db->table($table)->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    private function normalizePincode(string $pincode): string
    {
        return (string) preg_replace('/\D+/', '', $pincode);
    }

    /** Current coverage cache version (bumped on every sync). */
    private function version(): int
    {
        return (int) Cache::get('coverage:ver', 0);
    }
}
