<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\City;
use Marvel\Database\Models\CityVerticalServiceSetting as CVS;
use Marvel\Database\Models\GlobalVerticalSetting as GVS;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ServiceAvailabilityLog;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\Type;
use Marvel\Database\Models\VendorProductPrice;
use Marvel\Events\ServiceAvailabilityChanged;
use Marvel\Services\AvailabilityService;
use Marvel\Services\ServiceAvailabilityService;

/**
 * Operations Control Center — read + write the service-availability matrix.
 * Gated by operations.* (super-admin bypasses). Every write fires
 * ServiceAvailabilityChanged → audit row + cache bust (single write path).
 */
class ServiceAvailabilityController extends CoreController
{
    public function __construct(private ServiceAvailabilityService $availability)
    {
    }

    /**
     * PUBLIC — storefront availability check for one vertical in a city. Used by
     * the PDP to gate add-to-cart + show the maintenance message. Fail-open
     * (resolve() never throws; missing vertical ⇒ available).
     */
    public function check(Request $request)
    {
        $vertical = trim((string) $request->get('vertical', ''));
        if ($vertical === '') {
            return ['available' => true, 'status' => 'active', 'reason' => null, 'message' => null];
        }
        $city = $request->get('city');
        $result = $this->availability->resolve($vertical, $city !== null ? (string) $city : null);

        // City-level blocks carry the city's configured takeover content so ONE
        // endpoint drives the web and app screens. `city_maintenance` gets the
        // admin-authored maintenance block (cities.settings.maintenance);
        // `city_paused`/`city_disabled` get nothing extra — the clients render
        // their plain "not serviceable here" state. Fail-soft: content is
        // garnish, the block itself must never depend on it.
        if (
            !$result['available']
            && is_string($result['reason'] ?? null)
            && str_starts_with($result['reason'], 'city_')
            && !str_starts_with($result['reason'], 'city_vertical')
            && $city
        ) {
            try {
                // Canonical key, matching how the availability check itself resolved this city
                // one line above. The raw norm() looked up "south delhi" and found nothing, so a
                // shopper in a Delhi district got the bare block screen with none of the
                // maintenance copy written for their city.
                $cityRow = City::whereIn(
                    DB::raw('LOWER(name)'),
                    AvailabilityService::canonicalCityVariants((string) $city),
                )
                    ->orderByDesc('is_serviceable')
                    ->first();
                if ($cityRow && $result['reason'] === 'city_maintenance') {
                    $result['maintenance'] = (array) data_get($cityRow->settings, 'maintenance', []);
                }
            } catch (\Throwable $e) {
                // content lookup must never break the check
            }
        }
        return $result;
    }

    // ── Reads ───────────────────────────────────────────────────────────────

    /** Summary cards for the dashboard top row. Fail-soft: never 500 the panel. */
    public function overview(Request $request)
    {
        try {
            $verticals = GVS::where('vertical_slug', '!=', GVS::PLATFORM_SLUG)->get();
            // ACTIVE only — maintenance stopped counting as "taking orders"
            // when City::acceptsOrders() dropped it (2026-08 policy change).
            $citiesActive = City::where('status', City::STATUS_ACTIVE)
                ->where('is_serviceable', true)->count();
            $citiesTotal = City::count();

            return [
                'verticals_active'   => $verticals->where('is_active', true)->count(),
                'verticals_disabled' => $verticals->where('is_active', false)->count(),
                'verticals_total'    => count($this->availability->allVerticals()),
                'cities_active'      => $citiesActive,
                'cities_disabled'    => max(0, $citiesTotal - $citiesActive),
                'cities_total'       => $citiesTotal,
                'overrides'          => CVS::whereIn('status', CVS::BLOCKING)->count(),
                'platform'           => $this->availability->platformFlags(),
                // `shops` has no soft-deletes — no deleted_at column (was 500ing the whole endpoint).
                'active_vendors'     => DB::table('shops')->where('is_active', true)->count(),
                'online_partners'    => $this->onlinePartners(),
                'changes_24h'        => ServiceAvailabilityLog::where('created_at', '>=', now()->subDay())->count(),
                'recent'             => ServiceAvailabilityLog::orderByDesc('created_at')->limit(6)->get(),
            ];
        } catch (\Throwable $e) {
            // Safe zeros so the Service Control Center never errors on a metric hiccup.
            return [
                'verticals_active' => 0, 'verticals_disabled' => 0, 'verticals_total' => 0,
                'cities_active' => 0, 'cities_disabled' => 0, 'cities_total' => 0, 'overrides' => 0,
                'platform' => ['stop_platform' => false, 'stop_orders' => false, 'stop_deliveries' => false, 'maintenance' => false, 'message' => null],
                'active_vendors' => 0, 'online_partners' => 0, 'changes_24h' => 0, 'recent' => [],
            ];
        }
    }

    private function onlinePartners(): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('delivery_partners')) {
            return 0;
        }
        return (int) DB::table('delivery_partners')
            ->whereNull('deleted_at')
            ->where('is_online', true)
            ->where('location_updated_at', '>=', now()->subMinutes(10))
            ->count();
    }

    /** The full grid: verticals (global) + platform + cities with per-cell state. */
    public function matrix(Request $request)
    {
        $all = $this->availability->allVerticals();
        $globalRows = GVS::where('vertical_slug', '!=', GVS::PLATFORM_SLUG)->get()->keyBy('vertical_slug');

        $verticals = array_map(function ($slug) use ($globalRows) {
            $g = $globalRows->get($slug);
            return [
                'slug'                => $slug,
                'is_active'           => $g ? (bool) $g->is_active : true,
                'status'              => $g->status ?? 'active',
                'maintenance_message' => $g->maintenance_message ?? null,
            ];
        }, $all);

        $cities = City::orderBy('name')->get(['id', 'name', 'state_name', 'status', 'is_serviceable'])
            ->map(function (City $c) use ($all) {
                $cells = [];
                foreach ($all as $slug) {
                    $cells[$slug] = $this->availability->resolve($slug, $c->name);
                }
                return [
                    'id'             => $c->id,
                    'name'           => $c->name,
                    'state_name'     => $c->state_name,
                    'status'         => $c->status,
                    'is_serviceable' => $c->is_serviceable,
                    'cells'          => $cells,
                ];
            });

        return [
            'verticals' => $verticals,
            'platform'  => $this->availability->platformFlags(),
            'cities'    => $cities,
        ];
    }

    public function logs(Request $request)
    {
        $limit = (int) ($request->limit ?? 30);
        return ServiceAvailabilityLog::orderByDesc('created_at')
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->entity_type))
            ->paginate($limit);
    }

    // ── Writes (each fires the audit/bust event) ─────────────────────────────

    /** Toggle / configure a GLOBAL vertical switch. */
    public function setGlobal(Request $request)
    {
        $data = $request->validate([
            'vertical_slug'       => ['required', 'string', 'max:64'],
            'is_active'           => ['required', 'boolean'],
            'status'              => ['nullable', Rule::in(GVS::STATUSES)],
            'maintenance_message' => ['nullable', 'string', 'max:500'],
            'reason'              => ['nullable', 'string', 'max:500'],
        ]);
        $slug = $data['vertical_slug'];
        if (!$this->availability->isKnownVertical($slug) || $slug === GVS::PLATFORM_SLUG) {
            throw ValidationException::withMessages(['vertical_slug' => ['Unknown vertical.']]);
        }

        $row = GVS::firstOrNew(['vertical_slug' => $slug]);
        $old = $row->exists ? $this->snapGlobal($row) : null;
        $row->is_active = $data['is_active'];
        $row->status = $data['status'] ?? ($data['is_active'] ? 'active' : 'disabled');
        $row->maintenance_message = $data['maintenance_message'] ?? null;
        $row->updated_by = optional($request->user())->id;
        if (!$row->exists) {
            $row->created_by = optional($request->user())->id;
        }
        $row->save();

        $this->audit($request, 'global_vertical', $slug, $old, $this->snapGlobal($row), $data['reason'] ?? null);
        return $row->fresh();
    }

    /** Set a single CITY × VERTICAL cell. status=active removes the override (inherit). */
    public function setCityVertical(Request $request)
    {
        $data = $request->validate([
            'city_id'             => ['required', 'integer', 'exists:cities,id'],
            'vertical_slug'       => ['required', 'string', 'max:64'],
            'status'              => ['required', Rule::in(CVS::STATUSES)],
            'maintenance_message' => ['nullable', 'string', 'max:500'],
            'reason'              => ['nullable', 'string', 'max:500'],
        ]);
        if (!$this->availability->isKnownVertical($data['vertical_slug'])) {
            throw ValidationException::withMessages(['vertical_slug' => ['Unknown vertical.']]);
        }

        $existing = CVS::where('city_id', $data['city_id'])->where('vertical_slug', $data['vertical_slug'])->first();
        $old = $existing ? $this->snapCell($existing) : null;

        if ($data['status'] === CVS::STATUS_ACTIVE) {
            // Active = inherit; drop the override row to keep the matrix lean.
            optional($existing)->delete();
            $new = ['status' => 'active'];
        } else {
            $row = CVS::updateOrCreate(
                ['city_id' => $data['city_id'], 'vertical_slug' => $data['vertical_slug']],
                [
                    'status'              => $data['status'],
                    'maintenance_message' => $data['maintenance_message'] ?? null,
                    'updated_by'          => optional($request->user())->id,
                    'created_by'          => $existing->created_by ?? optional($request->user())->id,
                ]
            );
            $new = $this->snapCell($row);
        }

        $this->audit($request, 'city_vertical', $data['city_id'] . ':' . $data['vertical_slug'], $old, $new, $data['reason'] ?? null);
        return ['ok' => true];
    }

    /** Bulk operations across cities / verticals. */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'action'        => ['required', 'string', Rule::in([
                'enable_vertical_global', 'disable_vertical_global',
                'set_vertical_all_cities', 'set_vertical_cities',
                'enable_cities', 'disable_cities',
            ])],
            'vertical_slug' => ['nullable', 'string', 'max:64'],
            'status'        => ['nullable', Rule::in(CVS::STATUSES)],
            'city_ids'      => ['nullable', 'array'],
            'city_ids.*'    => ['integer', 'exists:cities,id'],
            'reason'        => ['nullable', 'string', 'max:500'],
        ]);
        $uid = optional($request->user())->id;
        $affected = 0;

        DB::transaction(function () use ($data, $uid, &$affected) {
            switch ($data['action']) {
                case 'enable_vertical_global':
                case 'disable_vertical_global':
                    $row = GVS::firstOrNew(['vertical_slug' => $data['vertical_slug']]);
                    $row->is_active = $data['action'] === 'enable_vertical_global';
                    $row->status = $row->is_active ? 'active' : 'disabled';
                    $row->updated_by = $uid;
                    $row->created_by = $row->created_by ?? $uid;
                    $row->save();
                    $affected = 1;
                    break;

                case 'set_vertical_all_cities':
                case 'set_vertical_cities':
                    $cityIds = $data['action'] === 'set_vertical_cities'
                        ? ($data['city_ids'] ?? [])
                        : City::pluck('id')->all();
                    $status = $data['status'] ?? CVS::STATUS_ACTIVE;
                    foreach ($cityIds as $cid) {
                        if ($status === CVS::STATUS_ACTIVE) {
                            CVS::where('city_id', $cid)->where('vertical_slug', $data['vertical_slug'])->delete();
                        } else {
                            CVS::updateOrCreate(
                                ['city_id' => $cid, 'vertical_slug' => $data['vertical_slug']],
                                ['status' => $status, 'updated_by' => $uid, 'created_by' => $uid]
                            );
                        }
                        $affected++;
                    }
                    break;

                case 'enable_cities':
                case 'disable_cities':
                    $enable = $data['action'] === 'enable_cities';
                    foreach (($data['city_ids'] ?? []) as $cid) {
                        $city = City::find($cid);
                        if (!$city) {
                            continue;
                        }
                        $city->status = $enable ? City::STATUS_ACTIVE : City::STATUS_DISABLED;
                        $city->is_serviceable = $enable;
                        $city->save();
                        $affected++;
                    }
                    break;
            }
        });

        $this->audit($request, 'bulk', $data['action'], null, [
            'action'        => $data['action'],
            'vertical_slug' => $data['vertical_slug'] ?? null,
            'status'        => $data['status'] ?? null,
            'affected'      => $affected,
        ], $data['reason'] ?? null);

        return ['ok' => true, 'affected' => $affected];
    }

    /** Emergency platform switches (kill-switch / maintenance). */
    public function emergency(Request $request)
    {
        $data = $request->validate([
            'flag'    => ['required', Rule::in(['stop_orders', 'stop_deliveries', 'stop_platform', 'maintenance'])],
            'on'      => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:500'],
            'reason'  => ['nullable', 'string', 'max:500'],
        ]);

        $row = GVS::firstOrNew(['vertical_slug' => GVS::PLATFORM_SLUG]);
        $settings = (array) ($row->settings ?? []);
        $old = ['settings' => $settings, 'message' => $row->maintenance_message];
        $settings[$data['flag']] = $data['on'];
        $row->settings = $settings;
        $row->is_active = empty($settings['stop_platform']);
        $row->status = !empty($settings['stop_platform']) ? 'disabled' : 'active';
        if ($request->filled('message')) {
            $row->maintenance_message = $data['message'];
        }
        $row->updated_by = optional($request->user())->id;
        $row->created_by = $row->created_by ?? optional($request->user())->id;
        $row->save();

        $this->audit($request, 'platform', $data['flag'], $old, ['settings' => $settings, 'message' => $row->maintenance_message], $data['reason'] ?? null);
        return $row->fresh();
    }

    // ── Seller model per vertical (single vendor = one seller nationwide, e.g. Tools) ──

    /** One row per catalogue vertical. */
    public function sellerModels()
    {
        return array_map(
            fn ($slug) => $this->sellerModel($slug),
            Type::query()->whereNotNull('slug')->distinct()->pluck('slug')->all(),
        );
    }

    public function showSellerModel(string $slug)
    {
        abort_unless(Type::where('slug', $slug)->exists(), 404, 'Unknown vertical.');
        return $this->sellerModel($slug);
    }

    /**
     * Switch a vertical between multi-vendor (city-based) and one seller nationwide.
     * Merged into `settings` (other keys survive). Multi → single is refused while other
     * shops still supply the vertical; a seller change is allowed and reports the old
     * seller's rows, which go inert (every read filters to the seller).
     */
    public function setSellerModel(Request $request, string $slug)
    {
        abort_unless(Type::where('slug', $slug)->exists(), 404, 'Unknown vertical.');
        $data = $request->validate([
            'seller_model'   => ['required', Rule::in(['single_vendor', 'multi_vendor'])],
            'seller_shop_id' => ['nullable', 'integer', 'required_if:seller_model,single_vendor'],
            'reason'         => ['nullable', 'string', 'max:500'],
        ]);
        $oldSeller = $this->availability->singleSellerShopId($slug);
        $newSeller = $data['seller_model'] === 'single_vendor' ? (int) $data['seller_shop_id'] : null;

        $products = Product::query()->select('id')->whereIn('type_id', Type::where('slug', $slug)->pluck('id'))
            ->where(fn ($q) => $q->whereNull('product_type')->orWhere('product_type', '!=', \Marvel\Enums\ProductType::BUNDLE));
        $warnings = [];
        $inert = 0;
        if ($newSeller !== null) {
            $shop = Shop::find($newSeller);
            if (!$shop || !$shop->is_active || $shop->approval_status === Shop::STATUS_ON_HOLD) {
                throw ValidationException::withMessages(['seller_shop_id' => ['The seller must be an active shop that is not on hold.']]);
            }
            $others = VendorProductPrice::whereIn('product_id', $products)->where('shop_id', '!=', $newSeller)->count();
            if ($oldSeller === null && $others > 0) {
                throw ValidationException::withMessages(['seller_model' => [
                    "{$others} inventory rows from other shops supply this vertical. Remove them before giving it a single seller.",
                ]]);
            }
            if ($others > 0) {
                $inert = $others;
                $warnings[] = "{$others} inventory rows from other shops (the previous seller) no longer sell anything.";
            }
            if (!VendorProductPrice::approved()->whereIn('product_id', $products)->where('shop_id', $newSeller)->exists()) {
                $warnings[] = 'The seller has no approved rates for this vertical yet, so its products show as out of stock.';
            }
        }

        $row = GVS::firstOrNew(['vertical_slug' => $slug]);
        $old = $row->exists ? ['settings' => $row->settings] : null;
        $row->settings = array_merge((array) ($row->settings ?? []), [
            'seller_model'   => $data['seller_model'],
            'seller_shop_id' => $newSeller,
        ]);
        if (!$row->exists) {
            $row->is_active = true;
            $row->status = GVS::STATUS_ACTIVE;
            $row->created_by = optional($request->user())->id;
        }
        $row->updated_by = optional($request->user())->id;
        $row->save();
        // Audit + bust() + products:ver, then re-mirror / re-project under the new model.
        $this->audit($request, 'global_vertical', $slug, $old, ['settings' => $row->settings], $data['reason'] ?? null);
        if ($oldSeller !== $newSeller) {
            $svc = new AvailabilityService();
            foreach ($products->pluck('id') as $pid) {
                $svc->recomputeForProduct((int) $pid);
            }
            AvailabilityService::bustCatalogCache();
        }

        return $this->sellerModel($slug) + ['warnings' => $warnings, 'inert_rows' => $inert];
    }

    /** @return array{vertical:string,seller_model:string,seller_shop_id:?int,seller:?array,city_based:bool} */
    private function sellerModel(string $slug): array
    {
        $sellerId = $this->availability->singleSellerShopId($slug);
        $shop = $sellerId ? Shop::find($sellerId, ['id', 'name', 'slug']) : null;
        return [
            'vertical'       => $slug,
            'seller_model'   => $sellerId ? 'single_vendor' : 'multi_vendor',
            'seller_shop_id' => $sellerId,
            'seller'         => $shop ? ['id' => (int) $shop->id, 'name' => $shop->name, 'slug' => $shop->slug] : null,
            'city_based'     => $sellerId === null,
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function snapGlobal(GVS $r): array
    {
        return ['is_active' => (bool) $r->is_active, 'status' => $r->status, 'maintenance_message' => $r->maintenance_message];
    }

    private function snapCell(CVS $r): array
    {
        return ['status' => $r->status, 'maintenance_message' => $r->maintenance_message];
    }

    private function audit(Request $request, string $type, ?string $id, ?array $old, ?array $new, ?string $reason): void
    {
        event(new ServiceAvailabilityChanged(
            $type,
            $id,
            $old,
            $new,
            $reason,
            optional($request->user())->id,
            $request->ip(),
        ));
    }
}
