<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Type;
use Marvel\Database\Models\VendorProductPrice;
use Marvel\Enums\ProductStatus;
use Marvel\Enums\ProductType;
use Marvel\Exceptions\MarvelException;
use Marvel\Services\BundleInventoryService;
use Marvel\Services\BundlePricingService;

/**
 * Admin-only Bundle Management. A bundle is a Product (product_type='bundle')
 * whose composition lives in product_inclusions; it holds no stock of its own
 * (derived from components) and appears on the storefront like any product.
 */
class BundleController extends CoreController
{
    public function __construct(
        protected BundleInventoryService $inventory,
        protected BundlePricingService $pricing,
    ) {
    }

    private const PRICING_MODES = ['fixed', 'percentage', 'flat', 'margin'];

    // ── Listing ──────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $limit = $request->limit ?: 15;

        $paginator = Product::query()
            ->where('product_type', ProductType::BUNDLE)
            ->with(['bundleItems:id,name,slug,image', 'shop:id,name'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->bundle_type, fn ($q) => $q->where('bundle_type', $request->bundle_type))
            ->when($request->shop_id, fn ($q) => $q->where('shop_id', $request->shop_id))
            ->when($request->name, fn ($q) => $q->where('name', 'like', "%{$request->name}%"))
            ->orderBy('display_priority', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($limit);

        $paginator->getCollection()->transform(fn (Product $b) => $this->slimBundle($b));

        return $paginator;
    }

    // ── Show (full admin payload — cost/margin allowed here) ──────────────────
    public function show($id)
    {
        $bundle = $this->findBundleOrFail($id);

        return $this->serializeBundle($bundle);
    }

    // ── Create (manual) ───────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $data = $this->validateBundle($request);
        $lines = $this->resolveLines($request->input('bundle_items', []));
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['bundle_items' => ['Select at least one valid plant.']]);
        }
        $this->assertPlantLimits($lines->count(), $request->input('bundle_config', []));

        $breakdown = $this->pricing->breakdown($data['pricing_mode'], (float) $data['pricing_value'], $lines);
        if (!$this->pricing->isValidFinal($breakdown['final_price'])) {
            throw ValidationException::withMessages([
                'pricing_value' => ['The resulting bundle price must be greater than zero. Check the pricing mode (margin needs vendor cost data).'],
            ]);
        }

        $bundle = $this->persistBundle($request, $data, $lines, $breakdown, 'manual', null);

        return $this->serializeBundle($bundle);
    }

    // ── Update ────────────────────────────────────────────────────────────────
    public function update(Request $request, $id)
    {
        $bundle = $this->findBundleOrFail($id);
        $data = $this->validateBundle($request);
        $lines = $this->resolveLines($request->input('bundle_items', []), (int) $bundle->id);
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['bundle_items' => ['Select at least one valid plant.']]);
        }
        $this->assertPlantLimits($lines->count(), $request->input('bundle_config', []));

        $breakdown = $this->pricing->breakdown($data['pricing_mode'], (float) $data['pricing_value'], $lines);
        if (!$this->pricing->isValidFinal($breakdown['final_price'])) {
            throw ValidationException::withMessages([
                'pricing_value' => ['The resulting bundle price must be greater than zero.'],
            ]);
        }

        $bundle = $this->persistBundle($request, $data, $lines, $breakdown, $bundle->bundle_type ?: 'manual', $bundle);

        return $this->serializeBundle($bundle);
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    public function destroy($id)
    {
        $bundle = $this->findBundleOrFail($id);
        $bundle->delete(); // product_inclusions cascade on parent_id
        $this->bustCache();

        return ['id' => (int) $id, 'deleted' => true];
    }

    // ── Toggle active ─────────────────────────────────────────────────────────
    public function toggle(Request $request, $id)
    {
        $bundle = $this->findBundleOrFail($id);
        $activate = $request->has('is_active')
            ? $request->boolean('is_active')
            : $bundle->status !== ProductStatus::PUBLISH;
        $bundle->status = $activate ? ProductStatus::PUBLISH : ProductStatus::DRAFT;
        $bundle->save();
        $this->bustCache();

        return $this->serializeBundle($bundle->fresh(['bundleItems']));
    }

    // ── Duplicate ─────────────────────────────────────────────────────────────
    public function duplicate(Request $request, $id)
    {
        $bundle = $this->findBundleOrFail($id);
        $items = $bundle->bundleItems()->get();

        $clone = $bundle->replicate(['slug']);
        $clone->name = $bundle->name . ' (Copy)';
        $clone->slug = $this->uniqueSlug($clone->name, $bundle->language);
        $clone->status = ProductStatus::DRAFT;
        $clone->created_by = optional($request->user())->id;
        $clone->save();

        $clone->syncInclusions(
            $items->map(fn ($p) => ['id' => $p->id, 'quantity' => (int) ($p->pivot->quantity ?: 1)])->all(),
            []
        );
        $this->snapshotStock($clone);
        $this->bustCache();

        return $this->serializeBundle($clone->fresh(['bundleItems']));
    }

    // ── Live preview (lenient — partial input yields an empty/partial preview) ──
    public function preview(Request $request)
    {
        $lines = $this->resolveLines($request->input('bundle_items', []), (int) $request->input('exclude_id', 0));
        $mode = $request->input('pricing_mode', 'fixed');
        $value = (float) $request->input('pricing_value', 0);
        $breakdown = $this->pricing->breakdown($mode, $value, $lines);

        $components = $lines->pluck('product');

        return [
            'matching_count'    => $lines->count(),
            'matching_products' => $this->slimComponents($lines),
            'price'             => $breakdown,
            'available_inventory' => $this->derivedFromLines($lines),
            'warnings'          => $this->lineWarnings($lines),
            'price_valid'       => $this->pricing->isValidFinal($breakdown['final_price']),
        ];
    }

    // ── Auto-generate bulk bundles (one per selected plant × bundle_qty) ───────
    public function generate(Request $request)
    {
        $request->validate([
            'plant_ids'      => ['required', 'array', 'min:1'],
            'plant_ids.*'    => ['integer'],
            'bundle_qty'     => ['required', 'integer', 'min:1'],
            'pricing_mode'   => ['required', 'string', 'in:' . implode(',', self::PRICING_MODES)],
            'pricing_value'  => ['required', 'numeric', 'min:0'],
            'status'         => ['nullable', 'string'],
            'name_template'  => ['nullable', 'string'],
        ]);

        $qty = (int) $request->bundle_qty;
        $mode = $request->pricing_mode;
        $value = (float) $request->pricing_value;
        $template = $request->name_template ?: '{plant} Bundle';
        $status = $request->status ?: ProductStatus::PUBLISH;
        $created = [];
        $skipped = [];

        $plantIds = array_values(array_unique(array_map('intval', (array) $request->plant_ids)));
        $plants = Product::whereIn('id', $plantIds)
            ->where('product_type', '!=', ProductType::BUNDLE)
            ->sellable()
            ->get()->keyBy('id');

        foreach ($plantIds as $pid) {
            $plant = $plants->get($pid);
            if (!$plant) {
                $skipped[] = ['plant_id' => (int) $pid, 'reason' => 'not_found_or_bundle'];
                continue;
            }

            $lines = collect([['product' => $plant, 'quantity' => $qty]]);
            $breakdown = $this->pricing->breakdown($mode, $value, $lines);
            if (!$this->pricing->isValidFinal($breakdown['final_price'])) {
                $skipped[] = ['plant_id' => (int) $pid, 'reason' => 'invalid_price'];
                continue;
            }

            try {
                DB::transaction(function () use ($plant, $qty, $mode, $value, $breakdown, $template, $status, $request, &$created) {
                    $name = str_replace('{plant}', $plant->name, $template);
                    $bundle = Product::create([
                        'name'             => $name,
                        'slug'             => $this->uniqueSlug($name, $plant->language ?: 'en'),
                        'description'      => $plant->name . ' — bundle of ' . $qty . '.',
                        'type_id'          => $plant->type_id,
                        'shop_id'          => $plant->shop_id ?: $this->defaultShopId($plant->type_id),
                        'language'         => $plant->language ?: 'en',
                        'product_type'     => ProductType::BUNDLE,
                        'bundle_type'      => 'auto',
                        'status'           => $status,
                        'visibility'       => 'visibility_public',
                        'in_stock'         => true,
                        'is_taxable'       => false,
                        'unit'             => $qty . ' Plants',
                        'image'            => $plant->image,
                        'pricing_mode'     => $mode,
                        'pricing_value'    => $value,
                        'price'            => $breakdown['final_price'],
                        'min_price'        => $breakdown['final_price'],
                        'max_price'        => $breakdown['final_price'],
                        'sale_price'       => null,
                        'created_by'       => optional($request->user())->id,
                        'bundle_config'    => ['rule' => $request->input('rule', []), 'bundle_qty' => $qty],
                    ]);
                    $bundle->syncInclusions([['id' => $plant->id, 'quantity' => $qty]], []);
                    $this->snapshotStock($bundle);
                    $created[] = $this->slimBundle($bundle->fresh(['bundleItems']));
                });
            } catch (\Throwable $e) {
                $skipped[] = ['plant_id' => (int) $pid, 'reason' => 'error'];
            }
        }

        $this->bustCache();

        return ['created' => $created, 'skipped' => $skipped];
    }

    // ── Eligible-plant filter (advanced, AND-combinable) ──────────────────────
    public function eligiblePlants(Request $request)
    {
        $limit = $request->limit ?: 40;
        $today = Carbon::today()->toDateString();

        // Cheapest available vendor cost per product (effective today).
        // approved() must match BundlePricingService's roll-up or the builder's cost
        // column disagrees with the saved breakdown.
        $costSub = VendorProductPrice::approved()
            ->select('product_id', DB::raw('MIN(cost_price) as min_cost'))
            ->where('is_available', true)
            ->where('cost_price', '>', 0)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->groupBy('product_id');

        $query = Product::query()
            ->leftJoinSub($costSub, 'vc', fn ($j) => $j->on('products.id', '=', 'vc.product_id'))
            ->where('products.product_type', '!=', ProductType::BUNDLE)
            // Only the curated, switched-on catalogue is bundleable — a bundle whose component
            // cannot be sold is broken the day it goes live.
            ->sellable()
            ->select('products.*', 'vc.min_cost')
            // status: default to published-only unless an explicit status is given.
            ->when($request->status, fn ($q) => $q->where('products.status', $request->status), fn ($q) => $q->where('products.status', ProductStatus::PUBLISH))
            ->when($request->filled('exclude_id'), fn ($q) => $q->where('products.id', '!=', (int) $request->exclude_id))
            ->when($request->name, fn ($q) => $q->where('products.name', 'like', "%{$request->name}%"))
            ->when($request->type_id, fn ($q) => $q->where('products.type_id', $request->type_id))
            ->when($request->filled('cost_min'), fn ($q) => $q->where('vc.min_cost', '>=', (float) $request->cost_min))
            ->when($request->filled('cost_max'), fn ($q) => $q->where('vc.min_cost', '<=', (float) $request->cost_max))
            ->when($request->filled('price_min'), fn ($q) => $q->whereRaw('COALESCE(products.sale_price, products.price, products.min_price, 0) >= ?', [(float) $request->price_min]))
            ->when($request->filled('price_max'), fn ($q) => $q->whereRaw('COALESCE(products.sale_price, products.price, products.min_price, 0) <= ?', [(float) $request->price_max]))
            ->when($request->boolean('in_stock_only'), fn ($q) => $q->where('products.quantity', '>', 0))
            ->when($request->categories, fn ($q) => $q->whereHas('categories', fn ($c) => $c->whereIn('categories.id', (array) $request->categories)))
            ->when($request->tags, fn ($q) => $q->whereHas('tags', fn ($t) => $t->whereIn('tags.id', (array) $request->tags)))
            ->when($request->vendor_ids, fn ($q) => $q->where(function ($w) use ($request) {
                $w->whereIn('products.shop_id', (array) $request->vendor_ids)
                    ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('vendor_product_prices')
                        ->whereColumn('vendor_product_prices.product_id', 'products.id')
                        ->whereIn('vendor_product_prices.shop_id', (array) $request->vendor_ids));
            }))
            ->orderByRaw('vc.min_cost IS NULL, vc.min_cost ASC')
            ->paginate($limit);

        $query->getCollection()->transform(fn (Product $p) => $this->slimEligible($p));

        return $query;
    }

    // ── Analytics ─────────────────────────────────────────────────────────────
    public function analytics(Request $request)
    {
        $bundles = Product::where('product_type', ProductType::BUNDLE)->get();
        $total = $bundles->count();
        $active = $bundles->where('status', ProductStatus::PUBLISH)->count();
        $manual = $bundles->where('bundle_type', 'manual')->count();
        $auto = $bundles->where('bundle_type', 'auto')->count();

        // Top bundles by units sold (order_product pivot on bundle product ids).
        $top = DB::table('order_product')
            ->join('products', 'products.id', '=', 'order_product.product_id')
            ->where('products.product_type', ProductType::BUNDLE)
            ->groupBy('products.id', 'products.name')
            ->select(
                'products.id',
                'products.name',
                // order_quantity is a VARCHAR column — CAST so SUM is numeric, not lexical.
                DB::raw('SUM(CAST(order_product.order_quantity AS UNSIGNED)) as units_sold'),
                DB::raw('SUM(order_product.subtotal) as revenue')
            )
            ->orderByDesc('units_sold')
            ->limit(10)
            ->get();

        return [
            'totals' => [
                'total'  => $total,
                'active' => $active,
                'manual' => $manual,
                'auto'   => $auto,
            ],
            'top_bundles' => $top,
        ];
    }

    // ══ Helpers ════════════════════════════════════════════════════════════════

    private function validateBundle(Request $request): array
    {
        $statuses = [
            ProductStatus::PUBLISH, ProductStatus::DRAFT, ProductStatus::UNPUBLISH,
            ProductStatus::UNDER_REVIEW, ProductStatus::APPROVED, ProductStatus::REJECTED,
        ];

        $validated = $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'description'       => ['nullable', 'string', 'max:10000'],
            'type_id'           => ['nullable', 'integer', 'exists:Marvel\Database\Models\Type,id'],
            'shop_id'           => ['nullable', 'integer', 'exists:Marvel\Database\Models\Shop,id'],
            'bundle_items'      => ['required', 'array', 'min:1'],
            'bundle_items.*.id' => ['required', 'integer'],
            'bundle_items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'pricing_mode'      => ['required', 'string', 'in:' . implode(',', self::PRICING_MODES)],
            'pricing_value'     => ['required', 'numeric', 'min:0'],
            'status'            => ['nullable', 'string'],
            'visibility'        => ['nullable', 'string'],
            'display_priority'  => ['nullable', 'integer'],
            'image'             => ['nullable'],
            'unit'              => ['nullable', 'string'],
            'language'          => ['nullable', 'string'],
            'categories'        => ['nullable', 'array'],
            'tags'              => ['nullable', 'array'],
            'bundle_config'     => ['nullable', 'array'],
        ]);

        if ($validated['pricing_mode'] === 'percentage' && (float) $validated['pricing_value'] > 100) {
            throw ValidationException::withMessages(['pricing_value' => ['A percentage discount cannot exceed 100%.']]);
        }
        if (!empty($validated['status']) && !in_array($validated['status'], $statuses, true)) {
            throw ValidationException::withMessages(['status' => ['Invalid status.']]);
        }

        return $validated;
    }

    /** Min/max plant-count limits from bundle_config. */
    private function assertPlantLimits(int $count, $config): void
    {
        $config = (array) $config;
        $min = isset($config['min_plants']) ? (int) $config['min_plants'] : null;
        $max = isset($config['max_plants']) ? (int) $config['max_plants'] : null;
        if ($min !== null && $count < $min) {
            throw ValidationException::withMessages(['bundle_items' => ["Select at least {$min} plants."]]);
        }
        if ($max !== null && $count > $max) {
            throw ValidationException::withMessages(['bundle_items' => ["Select at most {$max} plants."]]);
        }
    }

    /**
     * Load + validate the selected components into [['product'=>P,'quantity'=>q], ...].
     * Drops non-existent, bundle (no bundle-of-bundle) and self references.
     */
    private function resolveLines(array $items, int $excludeId = 0): Collection
    {
        $byId = [];
        foreach ($items as $item) {
            $id = (int) (is_array($item) ? ($item['id'] ?? 0) : $item);
            if ($id <= 0 || $id === $excludeId) {
                continue;
            }
            $qty = (int) (is_array($item) ? ($item['quantity'] ?? 1) : 1);
            $byId[$id] = max(1, $qty); // de-dupe; last quantity wins
        }
        if (empty($byId)) {
            return collect();
        }

        // Gated here as well as in the picker: this is what store/update/preview actually trust,
        // and a crafted payload never goes near eligiblePlants().
        $products = Product::whereIn('id', array_keys($byId))
            ->where('product_type', '!=', ProductType::BUNDLE)
            ->sellable()
            ->get();

        return $products->map(fn (Product $p) => ['product' => $p, 'quantity' => $byId[$p->id]])->values();
    }

    private function persistBundle(Request $request, array $data, Collection $lines, array $breakdown, string $bundleType, ?Product $existing): Product
    {
        $first = $lines->first()['product'];
        $typeId = $data['type_id'] ?? $existing?->type_id ?? $first->type_id;
        $shopId = $data['shop_id'] ?? $existing?->shop_id ?? ($first->shop_id ?: $this->defaultShopId($typeId));
        $language = $data['language'] ?? $existing?->language ?? ($first->language ?: 'en');
        $image = $request->has('image') ? $request->input('image') : ($existing?->image ?? $first->image);

        $attributes = [
            'name'             => $data['name'],
            'description'      => $data['description'] ?? null,
            'type_id'          => $typeId,
            'shop_id'          => $shopId,
            'language'         => $language,
            'product_type'     => ProductType::BUNDLE,
            'bundle_type'      => $bundleType,
            'status'           => $data['status'] ?? ProductStatus::PUBLISH,
            'visibility'       => $data['visibility'] ?? 'visibility_public',
            'in_stock'         => true,
            'is_taxable'       => false,
            'unit'             => $data['unit'] ?? ($lines->count() . ' Plants'),
            'image'            => $image,
            'pricing_mode'     => $data['pricing_mode'],
            'pricing_value'    => (float) $data['pricing_value'],
            'price'            => $breakdown['final_price'],
            'min_price'        => $breakdown['final_price'],
            'max_price'        => $breakdown['final_price'],
            'sale_price'       => null,
            'display_priority' => (int) ($data['display_priority'] ?? 0),
            'bundle_config'    => $request->input('bundle_config', $existing?->bundle_config ?? []),
        ];

        if ($existing) {
            $existing->update($attributes);
            $bundle = $existing;
        } else {
            $attributes['slug'] = $this->uniqueSlug($data['name'], $language);
            $attributes['created_by'] = optional($request->user())->id;
            $bundle = Product::create($attributes);
        }

        $bundle->syncInclusions(
            $lines->map(fn ($l) => ['id' => $l['product']->id, 'quantity' => $l['quantity']])->all(),
            []
        );

        if (array_key_exists('categories', $data) && is_array($data['categories'])) {
            $bundle->categories()->sync($data['categories']);
        }
        if (array_key_exists('tags', $data) && is_array($data['tags'])) {
            $bundle->tags()->sync($data['tags']);
        }

        $this->snapshotStock($bundle);
        $this->bustCache();

        return $bundle->fresh(['bundleItems']);
    }

    /** Store a derived-inventory snapshot into products.quantity (informational; recomputed live on read). */
    private function snapshotStock(Product $bundle): void
    {
        $bundle->loadMissing('bundleItems');
        $bundle->quantity = $this->inventory->available($bundle);
        $bundle->saveQuietly();
    }

    private function serializeBundle(Product $bundle): array
    {
        $bundle->loadMissing(['bundleItems', 'categories:id', 'tags:id']);
        $breakdown = $this->pricing->forBundle($bundle);

        return [
            'id'                  => (int) $bundle->id,
            'name'                => $bundle->name,
            'slug'                => $bundle->slug,
            'description'         => $bundle->description,
            'image'               => $bundle->image,
            'status'              => $bundle->status,
            'visibility'          => $bundle->visibility,
            'bundle_type'         => $bundle->bundle_type,
            'pricing_mode'        => $bundle->pricing_mode,
            'pricing_value'       => $bundle->pricing_value,
            'display_priority'    => (int) $bundle->display_priority,
            'bundle_config'       => $bundle->bundle_config ?? [],
            'type_id'             => $bundle->type_id,
            'shop_id'             => $bundle->shop_id,
            'created_by'          => $bundle->created_by,
            'created_at'          => $bundle->created_at,
            'language'            => $bundle->language,
            'categories'          => $bundle->categories->pluck('id'),
            'tags'                => $bundle->tags->pluck('id'),
            'bundle_items'        => $bundle->bundleItems->map(fn ($p) => [
                'id'         => $p->id,
                'name'       => $p->name,
                'slug'       => $p->slug,
                'image'      => $p->image,
                'price'      => (float) ($p->sale_price ?: $p->price ?: $p->min_price ?: 0),
                'quantity'   => (int) ($p->pivot->quantity ?: 1),
            ])->values(),
            'price'               => $breakdown,
            'available_inventory' => $this->inventory->available($bundle),
            'warnings'            => $this->inventory->componentWarnings($bundle),
        ];
    }

    private function slimBundle(Product $bundle): array
    {
        return [
            'id'                  => (int) $bundle->id,
            'name'                => $bundle->name,
            'slug'                => $bundle->slug,
            'image'               => $bundle->image,
            'bundle_type'         => $bundle->bundle_type,
            'status'              => $bundle->status,
            'plant_count'         => $bundle->relationLoaded('bundleItems') ? $bundle->bundleItems->count() : $bundle->bundleItems()->count(),
            'available_inventory' => $this->inventory->available($bundle),
            'price'               => (float) ($bundle->price ?? 0),
            'display_priority'    => (int) $bundle->display_priority,
            'created_by'          => $bundle->created_by,
            'created_at'          => $bundle->created_at,
        ];
    }

    private function slimEligible(Product $p): array
    {
        return [
            'id'                  => (int) $p->id,
            'name'                => $p->name,
            'slug'                => $p->slug,
            'image'               => $p->image,
            'product_type'        => $p->product_type,
            'price'               => (float) ($p->sale_price ?: $p->price ?: $p->min_price ?: 0),
            'cost_price'          => isset($p->min_cost) ? (float) $p->min_cost : null,
            'available_inventory' => $this->inventory->componentAvailable($p),
            'status'              => $p->status,
            'shop_id'             => $p->shop_id,
        ];
    }

    private function slimComponents(Collection $lines): array
    {
        return $lines->map(fn ($l) => [
            'id'       => (int) $l['product']->id,
            'name'     => $l['product']->name,
            'image'    => $l['product']->image,
            'price'    => (float) ($l['product']->sale_price ?: $l['product']->price ?: $l['product']->min_price ?: 0),
            'quantity' => (int) $l['quantity'],
        ])->values()->all();
    }

    /** Derived available bundles for an unsaved set of lines (MIN over components). */
    private function derivedFromLines(Collection $lines): int
    {
        if ($lines->isEmpty()) {
            return 0;
        }
        $min = null;
        foreach ($lines as $l) {
            $perBundle = max(1, (int) $l['quantity']);
            $avail = $this->inventory->componentAvailable($l['product']);
            $sellable = $l['product']->status === ProductStatus::PUBLISH;
            $units = $sellable ? intdiv(max(0, $avail), $perBundle) : 0;
            $min = $min === null ? $units : min($min, $units);
        }

        return (int) ($min ?? 0);
    }

    private function lineWarnings(Collection $lines): array
    {
        $warnings = [];
        foreach ($lines as $l) {
            $p = $l['product'];
            $flags = [];
            if ($p->status !== ProductStatus::PUBLISH) {
                $flags[] = 'inactive';
            } elseif ($this->inventory->componentAvailable($p) <= 0) {
                $flags[] = 'zero_stock';
            }
            if ($flags) {
                $warnings[] = ['id' => (int) $p->id, 'name' => $p->name, 'flags' => $flags];
            }
        }

        return $warnings;
    }

    private function findBundleOrFail($id): Product
    {
        try {
            return Product::where('product_type', ProductType::BUNDLE)->findOrFail($id);
        } catch (\Exception $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    private function defaultShopId(?int $typeId): ?int
    {
        return Product::query()
            ->when($typeId, fn ($q) => $q->where('type_id', $typeId))
            ->whereNotNull('shop_id')
            ->value('shop_id');
    }

    private function uniqueSlug(string $name, ?string $language): string
    {
        $base = Str::slug($name) ?: 'bundle';
        $slug = $base;
        $i = 2;
        while (Product::where('slug', $slug)->where('language', $language ?: 'en')->exists()) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }

    private function bustCache(): void
    {
        Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);
    }
}
