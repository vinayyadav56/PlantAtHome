<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\City;
use Marvel\Database\Models\CityVerticalServiceSetting as CVS;
use Marvel\Database\Models\GlobalVerticalSetting as GVS;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Type;
use Marvel\Enums\ProductType;

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
 *
 * A vertical whose `settings.seller_model` is 'single_vendor' (Tools) is sold by ONE
 * seller shop nationwide: tiers 2–3 never apply to it, and every "is this product
 * single-seller?" branch elsewhere asks singleSellerFor(). Absent ⇒ multi-vendor.
 *
 * A multi-vendor vertical whose `settings.catalogue_mode` is 'global' (Plants, while no
 * vendors are onboarded) is a GLOBAL CATALOGUE: city vendors still sell where they exist,
 * and everywhere else the product stays orderable at its catalogue price, fulfilled by
 * `settings.fallback_shop_id` (staff assign it). Only the city's "not serviceable yet"
 * flag stops applying; a paused city and the city × vertical matrix still block. Every
 * "can a fallback take this?" branch asks globalFallbackFor(). Absent ⇒ city-only.
 */
class ServiceAvailabilityService
{
    private const VER_KEY = 'service_availability:ver';
    private const TTL = 300;

    /** Per-instance memo so one request deserializes the map at most once. */
    private ?array $memo = null;
    private ?int $memoVer = null;
    /** product id => seller shop id|null, valid for $memoVer. */
    private array $sellerMemo = [];
    /** Global-catalogue verticals (slug => fallback shop id), valid for $memoVer. */
    private ?array $globalMemo = null;
    /** product id => fallback shop id|null, valid for $memoVer. */
    private array $fallbackMemo = [];
    /** shop id => can it take orders, per instance (the binding is scoped per request). */
    private array $shopMemo = [];
    /** The cache version as this instance last read or wrote it. */
    private ?int $verRead = null;

    /** Normalize a city name to the key convention used across the catalog. */
    public static function norm(?string $city): string
    {
        return strtolower(trim((string) $city));
    }

    /**
     * Read once per instance, not per call: the binding is scoped (one per request / queue
     * job) and Product::city_based asks for every serialized product.
     */
    private function version(): int
    {
        return $this->verRead ??= (int) Cache::get(self::VER_KEY, 1);
    }

    /** Bump the version so every reader recomputes the map immediately. */
    public function bust(): void
    {
        $this->verRead = (int) Cache::get(self::VER_KEY, 1) + 1;
        Cache::forever(self::VER_KEY, $this->verRead);
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
        // m3: adds global-catalogue keys; a new prefix so no reader ever sees an older shape.
        $map = Cache::remember('service_availability:m3:v' . $ver, self::TTL, function () {
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
                $s = (array) ($g->settings ?? []);
                $global[$g->vertical_slug] = [
                    'is_active' => (bool) $g->is_active,
                    'status'    => $g->status,
                    'message'   => $g->maintenance_message,
                    'seller_shop_id' => ($s['seller_model'] ?? null) === 'single_vendor' && !empty($s['seller_shop_id'])
                        ? (int) $s['seller_shop_id']
                        : null,
                    'fallback_shop_id' => ($s['catalogue_mode'] ?? null) === 'global' && !empty($s['fallback_shop_id'])
                        ? (int) $s['fallback_shop_id']
                        : null,
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
                // `active`: every same-named row is open (status active), serviceable or not —
                // all a global-catalogue vertical needs. Most-restrictive, like `accepts`.
                $open = $c->status === City::STATUS_ACTIVE;
                if (!isset($cities[$key])) {
                    $cities[$key] = ['ids' => [$c->id], 'accepts' => $accepts, 'status' => $c->status, 'active' => $open];
                } else {
                    $cities[$key]['ids'][] = $c->id;
                    $cities[$key]['active'] = $cities[$key]['active'] && $open;
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
                // slug => type ids (one per language row).
                'type_ids'      => Type::query()->whereNotNull('slug')->get(['id', 'slug'])
                    ->groupBy('slug')->map(fn ($g) => $g->pluck('id')->map(fn ($id) => (int) $id)->all())->all(),
                'global'        => $global,
                'platform'      => $platform,
                'cities'        => $cities,
                'matrix'        => $matrix,
            ];
        });

        $this->memo = $map;
        $this->memoVer = $ver;
        $this->sellerMemo = [];
        $this->globalMemo = null;
        $this->fallbackMemo = [];
        return $map;
    }

    /** The seller shop of a single-vendor vertical, or null (multi-vendor, the default). */
    public function singleSellerShopId(string $slug): ?int
    {
        try {
            return $this->map()['global'][$slug]['seller_shop_id'] ?? null;
        } catch (\Throwable $e) {
            return null; // fail open = today's multi-vendor behaviour
        }
    }

    /**
     * @return array<int,int> type_id => seller shop id, for every single-vendor vertical.
     * $activeOnly drops a vertical that is switched off or coming soon — for the city scope
     * only: pricing, city_based and the recompute must keep treating it as single-seller.
     */
    public function singleSellerTypeIds(bool $activeOnly = false): array
    {
        try {
            $map = $this->map();
            $out = [];
            foreach ($map['global'] as $slug => $g) {
                if (!empty($g['seller_shop_id']) && (!$activeOnly || self::isOn($g))) {
                    foreach ($map['type_ids'][$slug] ?? [] as $typeId) {
                        $out[(int) $typeId] = (int) $g['seller_shop_id'];
                    }
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The one shop that sells this product, or null: its vertical is multi-vendor, or it
     * is a bundle (a bundle inherits its first item's type, but has no vendor supply).
     * Pass the model when it is at hand; an id costs one memoised query.
     */
    public function singleSellerFor(Product|int $product): ?int
    {
        $sellers = $this->singleSellerTypeIds();
        if (!$sellers) {
            return null;
        }
        try {
            if ($product instanceof Product) {
                return $product->product_type !== ProductType::BUNDLE ? ($sellers[(int) $product->type_id] ?? null) : null;
            }
            if (!array_key_exists($product, $this->sellerMemo)) {
                $row = DB::table('products')->where('id', $product)->first(['type_id', 'product_type']);
                $this->sellerMemo[$product] = $row && $row->product_type !== ProductType::BUNDLE
                    ? ($sellers[(int) $row->type_id] ?? null)
                    : null;
            }
            return $this->sellerMemo[$product];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** A vertical row that is switched on: active, and neither disabled nor coming soon. */
    private static function isOn(array $g): bool
    {
        return !empty($g['is_active']) && !in_array($g['status'] ?? null, ['disabled', 'coming_soon'], true);
    }

    /**
     * Global-catalogue verticals that are live right now: slug => fallback shop id. A
     * vertical counts while it is switched on, multi-vendor, and its fallback shop can take
     * orders (checked per request — deactivating a shop does not bust the map cache).
     * Fail closed: on any fault, no vertical is global (today's city-only behaviour).
     *
     * @return array<string,int>
     */
    public function globalCatalogueVerticals(): array
    {
        try {
            $map = $this->map();
            if ($this->globalMemo !== null) {
                return $this->globalMemo;
            }
            $out = [];
            foreach ($map['global'] as $slug => $g) {
                $shop = $g['fallback_shop_id'] ?? null;
                if ($shop && empty($g['seller_shop_id']) && self::isOn($g) && $this->shopCanSell($shop)) {
                    $out[$slug] = $shop;
                }
            }
            return $this->globalMemo = $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * type_id => fallback shop for the global-catalogue verticals. With a city, only the
     * verticals resolve() allows there (a paused city or a matrix block drops them).
     *
     * @return array<int,int>
     */
    public function globalCatalogueTypeIds(?string $city = null): array
    {
        $verticals = $this->globalCatalogueVerticals();
        if (!$verticals) {
            return [];
        }
        try {
            $typeIds = $this->map()['type_ids'];
            $out = [];
            foreach ($verticals as $slug => $shop) {
                if ($city !== null && trim($city) !== '' && !$this->resolve($slug, $city)['available']) {
                    continue;
                }
                foreach ($typeIds[$slug] ?? [] as $typeId) {
                    $out[(int) $typeId] = $shop;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The shop that fulfils this product where no city vendor can — its vertical is a global
     * catalogue — or null. Bundles included: the fallback needs no vendor rows.
     */
    public function globalFallbackFor(Product|int $product): ?int
    {
        $types = $this->globalCatalogueTypeIds();
        if (!$types) {
            return null;
        }
        try {
            if ($product instanceof Product && array_key_exists('type_id', $product->getAttributes())) {
                return $types[(int) $product->type_id] ?? null;
            }
            $id = $product instanceof Product ? (int) $product->getKey() : (int) $product;
            if (!array_key_exists($id, $this->fallbackMemo)) {
                $typeId = DB::table('products')->where('id', $id)->value('type_id');
                $this->fallbackMemo[$id] = $typeId ? ($types[(int) $typeId] ?? null) : null;
            }
            return $this->fallbackMemo[$id];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Can this shop take orders: it exists, is active and is not on hold. */
    public function shopCanSell(int $shopId): bool
    {
        if (!array_key_exists($shopId, $this->shopMemo)) {
            try {
                $row = DB::table('shops')->where('id', $shopId)->first();
                $this->shopMemo[$shopId] = $row !== null && !empty($row->is_active)
                    && ($row->approval_status ?? null) !== \Marvel\Database\Models\Shop::STATUS_ON_HOLD;
            } catch (\Throwable $e) {
                $this->shopMemo[$shopId] = false;
            }
        }
        return $this->shopMemo[$shopId];
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

            // A single-vendor vertical (Tools) ships nationwide from its seller: city
            // status and the city × vertical matrix never apply to it.
            if (!empty($g['seller_shop_id'])) {
                return ['available' => true, 'status' => 'active', 'reason' => null, 'message' => null];
            }

            // Tier 2 — city status (existing City Activation Engine). Alias the lookup the
            // SAME way AvailabilityService does (New Delhi + Delhi NCT sub-districts ->
            // "delhi", Gurgaon -> "gurugram", ...) so a reverse-geocoded sub-district
            // address resolves to its canonical serviceable city instead of a
            // non-serviceable sub-district row. The map's city KEYS stay per-row
            // (self::norm on each name, above), so aliasing ONLY the lookup can't drag the
            // canonical city down via the most-restrictive same-key aggregation.
            $cityKey = ($city !== null && trim((string) $city) !== '')
                ? AvailabilityService::canonicalCityKey((string) $city)
                : '';
            $c = $cityKey !== '' ? ($map['cities'][$cityKey] ?? null) : null;
            // A global-catalogue vertical ships from its fallback shop, so a city that is open
            // but has no vendor yet (not serviceable) still takes its orders. A paused /
            // maintenance / disabled city blocks it like any other vertical.
            if ($c && !$c['accepts']
                && !(!empty($c['active']) && isset($this->globalCatalogueVerticals()[$vertical]))) {
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
        return self::norm($city) !== '' && $this->verticalFilterForCity($city) !== null;
    }

    /**
     * The verticals a listing should narrow to in this city, or null for no narrowing (fail
     * open). Narrows only when something is off AND a city-based vertical is still on.
     * Single-seller verticals are on everywhere, so on their own they never count: a city with
     * every city-based vertical off keeps the old browse-everything fallback rather than
     * turning into a Tools-only store.
     */
    public function verticalFilterForCity(?string $city): ?array
    {
        try {
            $available = $this->availableVerticalsForCity($city);
        } catch (\Throwable $e) {
            return null; // fail open: no narrowing
        }
        $singleSeller = [];
        try {
            foreach ($this->map()['global'] as $slug => $g) {
                if (!empty($g['seller_shop_id'])) {
                    $singleSeller[] = $slug;
                }
            }
        } catch (\Throwable $e) {
            // fail open: no single-seller verticals ⇒ today's rule
        }
        return array_diff($available, $singleSeller) && count($available) < count($this->allVerticals())
            ? $available
            : null;
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
