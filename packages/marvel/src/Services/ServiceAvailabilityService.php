<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\City;
use Marvel\Database\Models\CityVerticalServiceSetting as CVS;
use Marvel\Database\Models\GlobalVerticalSetting as GVS;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Type;

/**
 * Operations Control Center — the single source of truth for "is vertical X
 * available in city Y right now?".
 *
 * 3-tier priority (FAIL OPEN at every tier — unconfigured / unknown / error ⇒
 * available, so an empty config never hides the catalog or blocks checkout):
 *   0. platform kill-switch (__platform__ row)
 *   1. global vertical switch
 *   2. city status (the existing City Activation Engine — City::acceptsOrders)
 *   3. city × vertical override (the matrix)
 *
 * The whole resolved map is cached under a VERSIONED key on the file driver
 * (mirrors AvailabilityService::products:ver); any admin write calls bust().
 */
class ServiceAvailabilityService
{
    private const VER_KEY = 'service_availability:ver';
    private const TTL = 300;

    /** Per-instance memo so one request deserializes the map at most once. */
    private ?array $memo = null;
    private ?int $memoVer = null;

    /** Normalize a city name to the key convention used across the catalog. */
    public static function norm(?string $city): string
    {
        return strtolower(trim((string) $city));
    }

    private function version(): int
    {
        return (int) Cache::get(self::VER_KEY, 1);
    }

    /** Bump the version so every reader recomputes the map immediately. */
    public function bust(): void
    {
        Cache::forever(self::VER_KEY, $this->version() + 1);
    }

    /**
     * The full availability map, cached. Small (verticals × cities), cheap to
     * rebuild. Keys: all_verticals[], global{slug=>row}, platform{flags},
     * cities{norm=>{id,status,accepts}}, matrix{city_id=>{slug=>{status,message}}}.
     */
    public function map(): array
    {
        $ver = $this->version();
        if ($this->memo !== null && $this->memoVer === $ver) {
            return $this->memo;
        }
        $map = Cache::remember('service_availability:v' . $ver, self::TTL, function () {
            $global = [];
            $platform = ['stop_platform' => false, 'stop_orders' => false, 'stop_deliveries' => false, 'maintenance' => false, 'message' => null];
            foreach (GVS::all() as $g) {
                if ($g->vertical_slug === GVS::PLATFORM_SLUG) {
                    $s = (array) ($g->settings ?? []);
                    $platform = [
                        'stop_platform'   => (bool) ($s['stop_platform'] ?? false),
                        'stop_orders'     => (bool) ($s['stop_orders'] ?? false),
                        'stop_deliveries' => (bool) ($s['stop_deliveries'] ?? false),
                        'maintenance'     => (bool) ($s['maintenance'] ?? false),
                        'message'         => $g->maintenance_message,
                    ];
                    continue;
                }
                $global[$g->vertical_slug] = [
                    'is_active' => (bool) $g->is_active,
                    'status'    => $g->status,
                    'message'   => $g->maintenance_message,
                ];
            }

            // The storefront passes a free-text city WITHOUT a state, so two
            // same-named cities in different states collapse to one key. We
            // aggregate them FAIL-SAFE (most-restrictive: any non-accepting
            // city makes the key non-accepting) and keep ALL their ids so the
            // Tier-3 matrix check covers every same-named city.
            $cities = [];
            foreach (City::all(['id', 'name', 'status', 'is_serviceable']) as $c) {
                $key = self::norm($c->name);
                $accepts = $c->acceptsOrders();
                if (!isset($cities[$key])) {
                    $cities[$key] = ['ids' => [$c->id], 'accepts' => $accepts, 'status' => $c->status];
                } else {
                    $cities[$key]['ids'][] = $c->id;
                    if (!$accepts && $cities[$key]['accepts']) {
                        // Promote the blocking city's status as the reason.
                        $cities[$key]['accepts'] = false;
                        $cities[$key]['status'] = $c->status;
                    }
                }
            }

            $matrix = [];
            foreach (CVS::all(['city_id', 'vertical_slug', 'status', 'maintenance_message']) as $o) {
                $matrix[$o->city_id][$o->vertical_slug] = [
                    'status'  => $o->status,
                    'message' => $o->maintenance_message,
                ];
            }

            return [
                'all_verticals' => $this->computeAllVerticals(),
                'global'        => $global,
                'platform'      => $platform,
                'cities'        => $cities,
                'matrix'        => $matrix,
            ];
        });

        $this->memo = $map;
        $this->memoVer = $ver;
        return $map;
    }

    /** Every known vertical slug: catalog Type slugs ∪ the 2 service verticals. */
    private function computeAllVerticals(): array
    {
        $types = Type::query()->whereNotNull('slug')->distinct()->pluck('slug')->all();
        return array_values(array_unique(array_merge($types, GVS::SERVICE_VERTICALS)));
    }

    public function allVerticals(): array
    {
        return $this->map()['all_verticals'];
    }

    public function isKnownVertical(string $slug): bool
    {
        return $slug === GVS::PLATFORM_SLUG || in_array($slug, $this->allVerticals(), true);
    }

    /**
     * Resolve availability of a vertical (optionally in a city).
     * @return array{available:bool,status:string,reason:?string,message:?string}
     */
    public function resolve(string $vertical, ?string $city = null): array
    {
        try {
            $map = $this->map();

            // Tier 0 — platform kill-switch.
            if (!empty($map['platform']['stop_platform'])) {
                return $this->blocked('platform_down', $map['platform']['message']);
            }

            // Tier 1 — global vertical switch. Blocked when off / disabled / not-yet-launched.
            $g = $map['global'][$vertical] ?? null;
            if ($g && (!$g['is_active'] || in_array($g['status'], ['disabled', 'coming_soon'], true))) {
                return $this->blocked('vertical_disabled_global', $g['message'], $g['status']);
            }

            // Tier 2 — city status (existing City Activation Engine).
            $cityKey = self::norm($city);
            $c = $cityKey !== '' ? ($map['cities'][$cityKey] ?? null) : null;
            if ($c && !$c['accepts']) {
                return $this->blocked('city_' . $c['status'], null, $c['status']);
            }

            // Tier 3 — city × vertical override (matrix). Check EVERY same-named
            // city id (most-restrictive) so a duplicate-name city can't dodge it.
            if ($c) {
                foreach ($c['ids'] as $cid) {
                    $o = $map['matrix'][$cid][$vertical] ?? null;
                    if ($o && in_array($o['status'], CVS::BLOCKING, true)) {
                        return $this->blocked('city_vertical_' . $o['status'], $o['message'], $o['status']);
                    }
                }
            }

            return ['available' => true, 'status' => 'active', 'reason' => null, 'message' => null];
        } catch (\Throwable $e) {
            // FAIL OPEN on any error — never break the storefront/checkout.
            return ['available' => true, 'status' => 'active', 'reason' => null, 'message' => null];
        }
    }

    private function blocked(string $reason, ?string $message, string $status = 'disabled'): array
    {
        return ['available' => false, 'status' => $status, 'reason' => $reason, 'message' => $message];
    }

    /** The vertical slugs currently available in a city (for list-filtering). */
    public function availableVerticalsForCity(?string $city): array
    {
        $out = [];
        foreach ($this->allVerticals() as $slug) {
            if ($this->resolve($slug, $city)['available']) {
                $out[] = $slug;
            }
        }
        return $out;
    }

    /**
     * True when filtering a city's listing would actually narrow it (i.e. some
     * vertical is unavailable). When false, callers must NOT filter (fail open).
     */
    public function shouldFilterCity(?string $city): bool
    {
        if (self::norm($city) === '') {
            return false;
        }
        $available = $this->availableVerticalsForCity($city);
        $all = $this->allVerticals();
        return count($available) > 0 && count($available) < count($all);
    }

    /** Is a specific product available in a city? (maps the product's type → slug). */
    public function isProductAvailable(Product $product, ?string $city = null): bool
    {
        $slug = optional($product->type)->slug;
        if (!$slug) {
            return true; // no vertical ⇒ nothing to gate on
        }
        return $this->resolve($slug, $city)['available'];
    }

    /** Platform-level transactional flags (read by the middleware / checkout). */
    public function platformFlags(): array
    {
        return $this->map()['platform'];
    }
}
