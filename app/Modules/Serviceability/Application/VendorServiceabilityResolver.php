<?php

namespace App\Modules\Serviceability\Application;

use App\Modules\Serviceability\Infrastructure\Models\VendorCoverageRule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;

/**
 * THE serviceability question, asked once: can vendor V deliver vertical X to
 * pincode P, and with what promise?
 *
 * Before this class the answer came from five places that could disagree —
 * the coverage projection (pincode), the legacy service areas (city), the
 * pincode allow-list, the PDP's delivery-options lookup and the assignment
 * engine's own ladder — so a product could be listed in a city the checkout
 * then refused. Every gate now routes here.
 *
 * Deliberately PURE COVERAGE. The Operations Control Center (is this vertical
 * switched on in this city?) is a separate question every caller already asks
 * with the real shipping city; running it in here would run it twice and tie
 * the module to marvel. No fail-open policy either: this returns an explicit
 * verdict with a reason, and callers decide what to do with it.
 */
class VendorServiceabilityResolver
{
    /** Shared fallback promise — the two copies in marvel drift otherwise. */
    public const DEFAULT_LOCAL_ETA = 2;
    public const DEFAULT_COURIER_ETA = 5;

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    /**
     * @return array{serviceable:bool, reason:?string, source:?string, vertical_scope:string, state_id:?int, district_id:?int, city_id:?int, fulfillment_mode:?string, eta_days:?int, sla_bucket:?string}
     */
    public function resolve(int $shopId, string $pincode, string $vertical = VendorCoverageRule::VERTICAL_ALL): array
    {
        $vertical = VendorCoverageRule::normalizeVertical($vertical);
        $pin = $this->normalize($pincode);
        $miss = fn (string $reason, array $extra = []) => [
            'serviceable' => false, 'reason' => $reason, 'source' => null,
            'vertical_scope' => $vertical, 'state_id' => null, 'district_id' => null, 'city_id' => null,
            'fulfillment_mode' => null, 'eta_days' => null, 'sla_bucket' => null,
        ] + $extra;

        if (! preg_match('/^\d{6}$/', $pin)) {
            return $miss('invalid_pincode');
        }

        $geo = $this->db->table('postal_codes')->where('pincode', $pin)
            ->first(['state_id', 'district_id', 'city_id', 'status']);
        if (! $geo) {
            return $miss('unknown_pincode');
        }
        if ($geo->status !== 'active') {
            return $miss('pincode_inactive');
        }
        if (! $this->shopIsActive($shopId)) {
            return $miss('vendor_inactive');
        }

        $scope = $this->scopeFor($shopId, $vertical);
        $row = $this->db->table('vendor_covered_pincodes')
            ->where('shop_id', $shopId)->where('pincode', $pin)->where('vertical', $scope)
            ->first(['source', 'fulfillment_mode', 'eta_days', 'state_id', 'district_id', 'city_id']);

        if (! $row) {
            return $miss('not_covered') + ['vertical_scope' => $scope];
        }

        $mode = $row->fulfillment_mode ?: 'both';
        $eta = $row->eta_days !== null ? (int) $row->eta_days : $this->defaultEta($shopId, $mode);

        return [
            'serviceable'      => true,
            'reason'           => null,
            'source'           => $row->source,
            'vertical_scope'   => $scope,
            'state_id'         => $row->state_id !== null ? (int) $row->state_id : (int) $geo->state_id,
            'district_id'      => $row->district_id !== null ? (int) $row->district_id : ($geo->district_id !== null ? (int) $geo->district_id : null),
            'city_id'          => $row->city_id !== null ? (int) $row->city_id : ($geo->city_id !== null ? (int) $geo->city_id : null),
            'fulfillment_mode' => $mode,
            'eta_days'         => $eta,
            'sla_bucket'       => $this->slaBucket($eta),
        ];
    }

    /**
     * Every active vendor that can deliver this vertical to this pincode.
     *
     * Only the PROJECTION is cached. Vendor activation and the shop's default
     * SLA are read fresh every call: they change through paths that know
     * nothing about coverage, and caching them meant approving a vendor left
     * them invisible for up to an hour.
     *
     * @return array<int, array> shop_id => verdict
     */
    public function vendorsFor(string $pincode, string $vertical = VendorCoverageRule::VERTICAL_ALL): array
    {
        $vertical = VendorCoverageRule::normalizeVertical($vertical);
        $pin = $this->normalize($pincode);
        if (! preg_match('/^\d{6}$/', $pin)) {
            return [];
        }

        $rows = Cache::remember("coverage:v{$this->version()}:pin:{$pin}:{$vertical}", 3600, function () use ($pin, $vertical) {
            // Which vendors even scope this vertical? The rest answer from '*'.
            $named = $vertical === VendorCoverageRule::VERTICAL_ALL ? [] : array_flip(
                $this->db->table('vendor_coverage_rules')
                    ->where('vertical', $vertical)->where('is_active', true)
                    ->distinct()->pluck('shop_id')->map(fn ($id) => (int) $id)->all()
            );

            $out = [];
            $projected = $this->db->table('vendor_covered_pincodes')
                ->where('pincode', $pin)
                ->whereIn('vertical', array_unique([$vertical, VendorCoverageRule::VERTICAL_ALL]))
                ->orderBy('shop_id')
                ->get(['shop_id', 'vertical', 'source', 'fulfillment_mode', 'eta_days', 'state_id', 'district_id', 'city_id']);
            foreach ($projected as $row) {
                $shopId = (int) $row->shop_id;
                $scope = isset($named[$shopId]) ? $vertical : VendorCoverageRule::VERTICAL_ALL;
                if ($row->vertical !== $scope) {
                    continue; // a '*' row for a vendor that replaced it for this vertical
                }
                $out[$shopId] = [
                    'source'      => $row->source,
                    'scope'       => $scope,
                    'mode'        => $row->fulfillment_mode ?: 'both',
                    'eta'         => $row->eta_days !== null ? (int) $row->eta_days : null,
                    'state_id'    => $row->state_id !== null ? (int) $row->state_id : null,
                    'district_id' => $row->district_id !== null ? (int) $row->district_id : null,
                    'city_id'     => $row->city_id !== null ? (int) $row->city_id : null,
                ];
            }

            return $out;
        });

        if ($rows === []) {
            return [];
        }

        $shops = $this->db->getSchemaBuilder()->hasTable('shops')
            ? $this->db->table('shops')->whereIn('id', array_keys($rows))->where('is_active', 1)
                ->pluck('sla_default_days', 'id')->all()
            : array_fill_keys(array_keys($rows), null);

        $out = [];
        foreach ($rows as $shopId => $row) {
            if (! array_key_exists($shopId, $shops)) {
                continue; // deactivated since the projection was cached
            }
            $eta = $row['eta'] ?? ((int) ($shops[$shopId] ?? 0) ?: ($row['mode'] === 'local' ? self::DEFAULT_LOCAL_ETA : self::DEFAULT_COURIER_ETA));
            $out[$shopId] = [
                'serviceable'      => true,
                'reason'           => null,
                'source'           => $row['source'],
                'vertical_scope'   => $row['scope'],
                'state_id'         => $row['state_id'],
                'district_id'      => $row['district_id'],
                'city_id'          => $row['city_id'],
                'fulfillment_mode' => $row['mode'],
                'eta_days'         => $eta,
                'sla_bucket'       => $this->slaBucket($eta),
            ];
        }

        return $out;
    }

    /**
     * Is coverage configured for this pin at all — i.e. does any vendor project
     * into its STATE for this vertical? Callers fail OPEN when this is false:
     * one vendor's first rule in Karnataka must never make a Haryana pin
     * unserviceable. Pass $vertical = null for "any vertical".
     */
    public function configuredFor(string $pincode, ?string $vertical = null): bool
    {
        $pin = $this->normalize($pincode);
        $stateId = $pin === '' ? null : $this->db->table('postal_codes')->where('pincode', $pin)->value('state_id');
        if ($stateId === null) {
            // An unknown pin has no state to scope by — fall back to the
            // platform-wide answer rather than inventing coverage.
            return $this->db->table('vendor_covered_pincodes')->exists();
        }

        $scope = $vertical === null ? 'any' : VendorCoverageRule::normalizeVertical($vertical);

        return (bool) Cache::remember(
            "coverage:v{$this->version()}:configured:{$stateId}:{$scope}",
            300,
            fn () => $this->db->table('vendor_covered_pincodes')
                ->where('state_id', $stateId)
                ->when($scope !== 'any', fn ($q) => $q->whereIn('vertical', array_unique([$scope, VendorCoverageRule::VERTICAL_ALL])))
                ->exists(),
        );
    }

    /* ── internals ─────────────────────────────────────────────────────── */

    /** A vendor answers a vertical from its own rules if it wrote any, else from '*'. */
    private function scopeFor(int $shopId, string $vertical): string
    {
        if ($vertical === VendorCoverageRule::VERTICAL_ALL) {
            return $vertical;
        }

        return $this->db->table('vendor_coverage_rules')
            ->where('shop_id', $shopId)->where('vertical', $vertical)->where('is_active', true)->exists()
            ? $vertical
            : VendorCoverageRule::VERTICAL_ALL;
    }

    private function shopIsActive(int $shopId): bool
    {
        if (! $this->db->getSchemaBuilder()->hasTable('shops')) {
            return true; // isolated test DBs without the legacy table
        }

        return (bool) $this->db->table('shops')->where('id', $shopId)->where('is_active', 1)->exists();
    }

    private function defaultEta(int $shopId, string $mode): int
    {
        $shopDefault = $this->db->getSchemaBuilder()->hasTable('shops')
            ? (int) ($this->db->table('shops')->where('id', $shopId)->value('sla_default_days') ?? 0)
            : 0;

        return $shopDefault ?: ($mode === 'local' ? self::DEFAULT_LOCAL_ETA : self::DEFAULT_COURIER_ETA);
    }

    /** Coarse promise the storefront can show without printing a date. */
    private function slaBucket(?int $eta): ?string
    {
        return match (true) {
            $eta === null => null,
            $eta <= 0     => 'same_day',
            $eta === 1    => 'next_day',
            $eta <= 2     => '1_2',
            $eta <= 3     => '2_3',
            default       => '3_5',
        };
    }

    private function normalize(string $pincode): string
    {
        return (string) preg_replace('/\D+/', '', $pincode);
    }

    private function version(): int
    {
        return (int) Cache::get('coverage:ver', 0);
    }
}
