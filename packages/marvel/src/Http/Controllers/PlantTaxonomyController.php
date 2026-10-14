<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\PlantAttributeDefinition;
use Marvel\Database\Models\PlantAttributeTerm;
use Marvel\Database\Models\PlantCollection;
use Marvel\Database\Models\Product;
use Marvel\Enums\Permission;
use Marvel\Enums\ProductStatus;

/**
 * Admin catalog taxonomy: attribute definitions + their terms, dynamic collections, and
 * plant varieties. Everything here is admin-owned — vendors read it and attach inventory,
 * they never write it.
 */
class PlantTaxonomyController extends Controller
{
    private function assertCatalogAdmin(Request $request): void
    {
        $user = $request->user();
        // Super-admins always pass; the catalog.manage permission lets a Catalog Admin
        // designation do the same job without full platform access.
        if (!$user || !($user->hasPermissionTo(Permission::SUPER_ADMIN) || $user->can('catalog.manage'))) {
            abort(403, 'Catalog management requires an admin role.');
        }
    }

    // ── Attribute definitions ────────────────────────────────────────────────────

    /** GET plant-attributes — definitions + terms (public read: filters are built from this). */
    public function definitions(Request $request)
    {
        $q = PlantAttributeDefinition::with(array_merge(
            ['terms' => fn ($t) => $request->boolean('include_inactive') ? $t : $t->where('is_active', true)],
            $this->generalised() ? ['categories:id,name,slug'] : []
        ))->orderBy('sort')->orderBy('name');
        if (!$request->boolean('include_inactive')) {
            $q->active();
        }
        // ?type=tools — one vertical's attributes (plus the ones that apply to every vertical).
        if ($request->filled('type') && $this->generalised()) {
            $typeId = (int) DB::table('types')->where('slug', (string) $request->type)->orderBy('id')->value('id');
            $q->where(fn ($w) => $w->whereNull('type_id')->orWhere('type_id', $typeId));
        }
        return response()->json($q->get()->map(fn ($d) => $this->definitionPayload($d)));
    }

    /**
     * GET products/attribute-schema?type_id=&category_ids=1,2 — the attributes a product of that
     * vertical in those categories should describe (scopes inherit down the category tree), with
     * their options, units, limits and whether each is required before publishing. The product
     * form renders its Specifications section from this.
     */
    public function schema(Request $request)
    {
        $typeId = $request->filled('type_id') ? (int) $request->type_id : null;
        $raw = $request->input('category_ids', []);
        $categoryIds = array_values(array_filter(array_map('intval', is_string($raw) ? explode(',', $raw) : (array) $raw)));

        $definitions = app(\Marvel\Services\ProductAttributeSchema::class)->forProduct($typeId, $categoryIds);

        return response()->json(['data' => $definitions->map(fn ($d) => $this->definitionPayload($d) + ['is_required' => (bool) $d->is_required])->values()]);
    }

    /** One definition as the admin and the product form read it. */
    private function definitionPayload(PlantAttributeDefinition $d): array
    {
        return [
            'id'              => $d->id,
            'code'            => $d->code ?: $d->slug,
            'slug'            => $d->slug,
            'name'            => $d->name,
            'type'            => $d->type,
            'unit'            => $d->unit,
            'min_value'       => $d->min_value,
            'max_value'       => $d->max_value,
            'step'            => $d->step,
            'max_length'      => $d->max_length,
            'type_id'         => $d->type_id,
            'is_facet'        => (bool) $d->is_facet,
            'is_active'       => (bool) $d->is_active,
            'is_variant_axis' => (bool) $d->is_variant_axis,
            'help_text'       => $d->help_text,
            'sort'            => (int) $d->sort,
            'terms'           => $d->relationLoaded('terms')
                ? $d->terms->map(fn ($t) => ['id' => $t->id, 'value' => $t->value, 'slug' => $t->slug, 'sort' => (int) $t->sort, 'is_active' => (bool) $t->is_active])->values()
                : [],
            'scopes'          => $d->relationLoaded('categories')
                ? $d->categories->map(fn ($c) => ['category_id' => $c->id, 'name' => $c->name ?? null, 'slug' => $c->slug ?? null, 'is_required' => (bool) $c->pivot->is_required])->values()
                : [],
        ];
    }

    public function storeDefinition(Request $request)
    {
        $this->assertCatalogAdmin($request);
        $data = $request->validate($this->definitionRules(true) + [
            'name' => 'required|string|max:191',
            'type' => 'required|in:' . implode(',', PlantAttributeDefinition::TYPES),
            'code' => 'nullable|string|max:64|regex:/^[a-z0-9-]+$/',
        ]);
        $scopes = $data['scopes'] ?? null;
        unset($data['scopes']);
        $data['slug'] = $this->uniqueSlug($data['name'], 'plant_attribute_definitions');
        if ($this->generalised()) {
            // The stable code filters and imports refer to: chosen once, never rewritten.
            $code = $data['code'] ?? $data['slug'];
            if (PlantAttributeDefinition::withTrashed()->where('code', $code)->exists()) {
                return response()->json(['code' => ["The code \"{$code}\" is already used by another attribute."]], 422);
            }
            $data['code'] = $code;
        } else {
            // Before the 2026_10_15_000200 migration only the original columns exist.
            $data = array_intersect_key($data, array_flip(['name', 'type', 'is_facet', 'is_active', 'sort', 'slug']));
        }
        $data['created_by_user_id'] = $request->user()?->id;
        $def = PlantAttributeDefinition::create($data);
        if ($scopes !== null && $this->generalised()) {
            $this->syncScopes($def, $scopes);
        }
        $this->bustProducts();
        return response()->json($this->definitionPayload($def->fresh($this->generalised() ? ['terms', 'categories'] : ['terms'])), 201);
    }

    public function updateDefinition(Request $request, int $id)
    {
        $this->assertCatalogAdmin($request);
        $def = PlantAttributeDefinition::findOrFail($id);
        $data = $request->validate($this->definitionRules(false) + [
            'name' => 'sometimes|string|max:191',
            'type' => 'sometimes|in:' . implode(',', PlantAttributeDefinition::TYPES),
        ]);
        // Changing the kind of value under existing products would orphan their values.
        if (isset($data['type']) && $data['type'] !== $def->type
            && DB::table('plant_attribute_product')->where('definition_id', $def->id)->exists()) {
            return response()->json(['type' => ['Products already use this attribute: its kind of value cannot change. Retire it and create a new one.']], 422);
        }
        $scopes = $data['scopes'] ?? null;
        unset($data['scopes'], $data['code']);
        if (!$this->generalised()) {
            $data = array_intersect_key($data, array_flip(['name', 'type', 'is_facet', 'is_active', 'sort']));
        }
        $def->fill($data)->save();
        if ($scopes !== null && $this->generalised()) {
            $this->syncScopes($def, $scopes);
        }
        $this->bustProducts();
        return response()->json($this->definitionPayload($def->fresh($this->generalised() ? ['terms', 'categories'] : ['terms'])));
    }

    /** POST plant-attributes/reorder {ids: [...]} — the order of attributes on forms and filters. */
    public function reorderDefinitions(Request $request)
    {
        $this->assertCatalogAdmin($request);
        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];
        foreach ($ids as $i => $definitionId) {
            PlantAttributeDefinition::where('id', $definitionId)->update(['sort' => ($i + 1) * 10]);
        }
        $this->bustProducts();
        return response()->json(['ok' => true]);
    }

    private function definitionRules(bool $creating): array
    {
        $sometimes = $creating ? 'nullable' : 'sometimes';
        return [
            'is_facet'               => "{$sometimes}|boolean",
            'is_active'              => "{$sometimes}|boolean",
            'is_variant_axis'        => "{$sometimes}|boolean",
            'sort'                   => "{$sometimes}|integer",
            'unit'                   => 'nullable|string|max:16',
            'min_value'              => 'nullable|numeric',
            'max_value'              => 'nullable|numeric',
            'step'                   => 'nullable|numeric|min:0',
            'max_length'             => 'nullable|integer|min:1|max:5000',
            'type_id'                => 'nullable|integer|exists:types,id',
            'help_text'              => 'nullable|string|max:255',
            'scopes'                 => 'nullable|array',
            'scopes.*.category_id'   => 'required|integer|exists:categories,id',
            'scopes.*.is_required'   => 'nullable|boolean',
        ];
    }

    /** Exactly these category scopes (a definition with none applies to its whole vertical). */
    private function syncScopes(PlantAttributeDefinition $def, array $scopes): void
    {
        $rows = [];
        foreach ($scopes as $i => $scope) {
            $rows[(int) $scope['category_id']] = ['is_required' => (bool) ($scope['is_required'] ?? false), 'sort' => $i];
        }
        $def->categories()->sync($rows);
    }

    /** Per instance, never static: a process-wide memo outlives the schema it read (tests). */
    private ?bool $generalised = null;

    /** Whether the attribute generalisation migration (code, units, scopes) has run. */
    private function generalised(): bool
    {
        return $this->generalised ??= Schema::hasColumn('plant_attribute_definitions', 'code') && Schema::hasTable('plant_attribute_scopes');
    }

    /** Facets, product lists and product pages cache on products:ver. */
    private function bustProducts(): void
    {
        \Illuminate\Support\Facades\Cache::forever('products:ver', (int) \Illuminate\Support\Facades\Cache::get('products:ver', 1) + 1);
    }

    /** Deactivate rather than delete: assignments and historical filters keep resolving. */
    public function destroyDefinition(Request $request, int $id)
    {
        $this->assertCatalogAdmin($request);
        $def = PlantAttributeDefinition::findOrFail($id);
        $def->update(['is_active' => false]);
        return response()->json(['ok' => true, 'deactivated' => true]);
    }

    public function storeTerm(Request $request, int $definitionId)
    {
        $this->assertCatalogAdmin($request);
        $def = PlantAttributeDefinition::findOrFail($definitionId);
        $data = $request->validate([
            'value' => 'required|string|max:191',
            'sort'  => 'nullable|integer',
        ]);
        $slug = Str::slug($data['value']);
        if (PlantAttributeTerm::where('definition_id', $def->id)->where('slug', $slug)->exists()) {
            return response()->json(['message' => 'That value already exists for this attribute.'], 422);
        }
        $term = PlantAttributeTerm::create([
            'definition_id' => $def->id, 'value' => $data['value'], 'slug' => $slug,
            'sort' => $data['sort'] ?? 0, 'is_active' => true,
        ]);
        $this->bustProducts();
        return response()->json($term, 201);
    }

    public function updateTerm(Request $request, int $id)
    {
        $this->assertCatalogAdmin($request);
        $term = PlantAttributeTerm::findOrFail($id);
        $term->fill($request->validate([
            'value'     => 'sometimes|string|max:191',
            'sort'      => 'sometimes|integer',
            'is_active' => 'sometimes|boolean',
        ]))->save();
        $this->bustProducts();
        return response()->json($term);
    }

    /** POST plant-attributes/{id}/terms/reorder {ids: [...]} — display order for filters. */
    public function reorderTerms(Request $request, int $definitionId)
    {
        $this->assertCatalogAdmin($request);
        $ids = $request->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];
        foreach ($ids as $i => $termId) {
            PlantAttributeTerm::where('definition_id', $definitionId)->where('id', $termId)->update(['sort' => ($i + 1) * 10]);
        }
        return response()->json(['ok' => true]);
    }

    // ── Collections ─────────────────────────────────────────────────────────────

    /** GET plant-collections — public (storefront renders these as discovery rows/pages). */
    public function collections(Request $request)
    {
        $q = PlantCollection::orderBy('sort')->orderBy('name');
        if (!$request->boolean('include_inactive')) {
            $q->active();
        }
        if ($request->filled('slug')) {
            $q->where('slug', $request->slug);
        }
        return response()->json($q->get());
    }

    public function storeCollection(Request $request)
    {
        $this->assertCatalogAdmin($request);
        $data = $request->validate([
            'name'            => 'required|string|max:191',
            'description'     => 'nullable|string',
            'rules'           => 'required|array|min:1',
            'image'           => 'nullable|array',
            'seo_title'       => 'nullable|string|max:191',
            'seo_description' => 'nullable|string',
            'is_active'       => 'nullable|boolean',
            'sort'            => 'nullable|integer',
        ]);
        $data['slug'] = $this->uniqueSlug($data['name'], 'plant_collections');
        return response()->json(PlantCollection::create($data), 201);
    }

    public function updateCollection(Request $request, int $id)
    {
        $this->assertCatalogAdmin($request);
        $c = PlantCollection::findOrFail($id);
        $c->fill($request->validate([
            'name'            => 'sometimes|string|max:191',
            'description'     => 'sometimes|nullable|string',
            'rules'           => 'sometimes|array|min:1',
            'image'           => 'sometimes|nullable|array',
            'seo_title'       => 'sometimes|nullable|string|max:191',
            'seo_description' => 'sometimes|nullable|string',
            'is_active'       => 'sometimes|boolean',
            'sort'            => 'sometimes|integer',
        ]))->save();
        return response()->json($c->fresh());
    }

    public function destroyCollection(Request $request, int $id)
    {
        $this->assertCatalogAdmin($request);
        PlantCollection::findOrFail($id)->delete(); // soft delete
        return response()->json(['ok' => true]);
    }

    // ── Varieties ───────────────────────────────────────────────────────────────

    /**
     * GET plant-varieties — masters with their varieties.
     * A "master" is any plant that is not itself a variety.
     */
    public function varieties(Request $request)
    {
        $limit = min(100, max(1, (int) ($request->limit ?? 20)));
        $q = Product::query()
            ->whereNull('master_product_id')
            ->when($request->filled('search'), fn ($x) => $x->where('name', 'like', '%' . trim((string) $request->search) . '%'))
            ->when($request->boolean('only_with_varieties'), fn ($x) => $x->whereHas('varieties'))
            ->with(['varieties:id,name,slug,variety_name,master_product_id,status,image'])
            ->withCount('varieties')
            ->orderBy('name');
        $page = $q->paginate($limit);
        $page->getCollection()->each(fn ($p) => $p->setAppends([]));
        return response()->json($page);
    }

    /**
     * POST plant-varieties — create a variety OF a master plant.
     *
     * A variety is a first-class product (own slug, PDP, sizes, vendor inventory) that
     * inherits the master's botanical record and categories, so vendors attach inventory
     * to it exactly like any other plant and orders/pricing need no new concepts.
     */
    public function storeVariety(Request $request)
    {
        $this->assertCatalogAdmin($request);
        $data = $request->validate([
            'master_product_id' => 'required|integer',
            'variety_name'      => 'required|string|max:191',
            'botanical_name'    => 'nullable|string|max:191',
            'description'       => 'nullable|string',
            'image'             => 'nullable|array',
        ]);

        $master = Product::whereNull('master_product_id')->findOrFail($data['master_product_id']);
        $name = "{$master->name} — {$data['variety_name']}";
        if (Product::where('name', $name)->exists()) {
            return response()->json(['message' => 'That variety already exists for this plant.'], 422);
        }

        $variety = null;
        DB::transaction(function () use ($master, $data, $name, &$variety) {
            $variety = Product::create([
                'name'              => $name,
                'slug'              => $this->uniqueSlug($name, 'products'),
                'master_product_id' => $master->id,
                'variety_name'      => $data['variety_name'],
                'description'       => $data['description'] ?? $master->description,
                'image'             => $data['image'] ?? $master->image,
                'type_id'           => $master->type_id,
                'shop_id'           => $master->shop_id,
                'product_type'      => $master->product_type,
                'price'             => $master->price,
                'sale_price'        => $master->sale_price,
                'min_price'         => $master->min_price,
                'max_price'         => $master->max_price,
                'unit'              => $master->unit,
                'status'            => ProductStatus::PUBLISH,
                'language'          => $master->language,
                'quantity'          => 0,
            ]);
            // Inherit classification + botanical record — a variety is the same plant.
            $catIds = DB::table('category_product')->where('product_id', $master->id)->pluck('category_id');
            foreach ($catIds as $cid) {
                DB::table('category_product')->insert(['category_id' => $cid, 'product_id' => $variety->id]);
            }
            $pa = DB::table('plant_attributes')->where('product_id', $master->id)->first();
            if ($pa) {
                $clone = (array) $pa;
                unset($clone['id']);
                $clone['product_id'] = $variety->id;
                $clone['scientific_name'] = ($data['botanical_name'] ?? null) ?: ($clone['scientific_name'] ?? null);
                $clone['created_at'] = now();
                $clone['updated_at'] = now();
                DB::table('plant_attributes')->insert($clone);
            }
            $terms = DB::table('plant_attribute_product')->where('product_id', $master->id)->get();
            foreach ($terms as $t) {
                DB::table('plant_attribute_product')->insert([
                    'definition_id' => $t->definition_id, 'term_id' => $t->term_id,
                    'product_id' => $variety->id, 'value_text' => $t->value_text,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        // Drop the model's default $appends (ratings/reviews/blocked_dates) — each is an
        // accessor that fires its own query, and none of it is meaningful on a row created
        // one line ago.
        $fresh = $variety->fresh();
        $fresh->setAppends([]);
        return response()->json($fresh, 201);
    }

    // ── Product ↔ term assignment ───────────────────────────────────────────────

    /** PUT products/{id}/plant-terms {term_ids: []} — the attribute-assignment panel. */
    public function syncProductTerms(Request $request, int $productId)
    {
        $this->assertCatalogAdmin($request);
        $termIds = $request->validate(['term_ids' => 'present|array', 'term_ids.*' => 'integer'])['term_ids'];
        $product = Product::findOrFail($productId);

        $terms = PlantAttributeTerm::whereIn('id', $termIds)->get(['id', 'definition_id']);
        DB::table('plant_attribute_product')->where('product_id', $product->id)->delete();
        if ($terms->isNotEmpty()) {
            DB::table('plant_attribute_product')->insert($terms->map(fn ($t) => [
                'definition_id' => $t->definition_id, 'term_id' => $t->id, 'product_id' => $product->id,
                'created_at' => now(), 'updated_at' => now(),
            ])->all());
        }
        return response()->json(['ok' => true, 'count' => $terms->count()]);
    }

    /** GET catalog-summary — the catalog dashboard counters. */
    public function summary(Request $request)
    {
        $this->assertCatalogAdmin($request);
        return response()->json([
            'total_plants'      => Product::whereNull('master_product_id')->count(),
            'active_plants'     => Product::whereNull('master_product_id')->where('status', ProductStatus::PUBLISH)->count(),
            'varieties'         => Product::whereNotNull('master_product_id')->count(),
            'categories'        => DB::table('categories')->whereNull('deleted_at')->where('is_active', true)->count(),
            'attributes'        => PlantAttributeDefinition::active()->count(),
            'collections'       => PlantCollection::active()->count(),
            'pending_proposals' => Product::where('status', ProductStatus::UNDER_REVIEW)->count(),
        ]);
    }

    private function uniqueSlug(string $name, string $table): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 1;
        while (DB::table($table)->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$i);
        }
        return $slug;
    }
}
