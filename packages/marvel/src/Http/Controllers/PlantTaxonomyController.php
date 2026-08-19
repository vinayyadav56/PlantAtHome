<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
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
        $q = PlantAttributeDefinition::with(['terms' => fn ($t) => $request->boolean('include_inactive') ? $t : $t->where('is_active', true)])
            ->orderBy('sort')->orderBy('name');
        if (!$request->boolean('include_inactive')) {
            $q->active();
        }
        return response()->json($q->get());
    }

    public function storeDefinition(Request $request)
    {
        $this->assertCatalogAdmin($request);
        $data = $request->validate([
            'name'      => 'required|string|max:191',
            'type'      => 'required|in:' . implode(',', PlantAttributeDefinition::TYPES),
            'is_facet'  => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort'      => 'nullable|integer',
        ]);
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['created_by_user_id'] = $request->user()?->id;
        return response()->json(PlantAttributeDefinition::create($data), 201);
    }

    public function updateDefinition(Request $request, int $id)
    {
        $this->assertCatalogAdmin($request);
        $def = PlantAttributeDefinition::findOrFail($id);
        $def->fill($request->validate([
            'name'      => 'sometimes|string|max:191',
            'type'      => 'sometimes|in:' . implode(',', PlantAttributeDefinition::TYPES),
            'is_facet'  => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'sort'      => 'sometimes|integer',
        ]))->save();
        return response()->json($def->fresh('terms'));
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
        return response()->json(PlantAttributeTerm::create([
            'definition_id' => $def->id, 'value' => $data['value'], 'slug' => $slug,
            'sort' => $data['sort'] ?? 0, 'is_active' => true,
        ]), 201);
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
                'slug'              => $this->uniqueSlug($name),
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

    private function uniqueSlug(string $name, string $table = 'products'): string
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
