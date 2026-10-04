<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\City;
use Marvel\Database\Models\LocationPage;
use Marvel\Exceptions\MarvelException;
use Marvel\Services\AvailabilityService;

/**
 * City landing pages. Public reads serve ONLY active rows; the storefront
 * 308s alias slugs (gurgaon -> gurugram) using the canonical slug we return.
 */
class LocationPageController extends CoreController
{
    /**
     * A city page is only worth indexing when it can show a real shelf — the
     * storefront renders 8 products, so fewer than that is a thin page. The
     * admin flag can still switch a page off, never on past this gate.
     */
    public const MIN_INDEXABLE_PRODUCTS = 8;

    /* ── public (storefront + sitemap) ────────────────────────────── */

    public function index(Request $request)
    {
        $rows = LocationPage::where('is_active', true)
            ->orderBy('city_name')
            ->get(['slug', 'city_name', 'state_name', 'is_indexable'])
            ->each(fn (LocationPage $p) => $this->withLiveSupply($p));
        // Footer "Plants in <city>" links — fetched on every storefront page, changes
        // only when a landing page is published. Cache anonymous reads at the edge.
        if (empty($request->bearerToken())) {
            return response()->json($rows)
                ->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=600');
        }
        return $rows;
    }

    public function show(string $slug)
    {
        // An alias slug ("gurgaon", "new-delhi") must find the canonical page:
        // hyphens -> spaces, through the shared alias table, back to a slug.
        $canonical = Str::slug(AvailabilityService::canonicalCityKey(str_replace('-', ' ', $slug)));

        $page = LocationPage::where('is_active', true)
            ->whereIn('slug', array_unique([$slug, $canonical]))
            ->first();

        if (! $page) {
            return response()->json(['message' => 'Location page not found.'], 404);
        }

        $this->withLiveSupply($page, withCategories: true);

        return $page->makeHidden(['created_by', 'updated_by']);
    }

    /**
     * Live supply, computed — never stored, so a city that loses stock drops
     * out of the index (and the sitemap, which reads the same flag) on its own.
     * products_count = published products orderable in the city (the same
     * availability query the city-first storefront filters with).
     */
    private function withLiveSupply(LocationPage $page, bool $withCategories = false): void
    {
        $key = AvailabilityService::canonicalCityKey($page->city_name);
        $supply = Cache::remember("location-page-supply:v1:{$key}", 600, function () use ($key) {
            $ids = app(AvailabilityService::class)->availabilityProductIdQuery($key);
            $published = DB::table('products')->whereIn('id', $ids)->where('status', 'publish');

            return [
                'products_count' => (clone $published)->count(),
                'categories' => DB::table('categories')
                    ->join('category_product', 'category_product.category_id', '=', 'categories.id')
                    ->whereIn('category_product.product_id', $published->select('id'))
                    ->groupBy('categories.id', 'categories.slug', 'categories.name')
                    ->orderByDesc(DB::raw('count(*)'))
                    ->limit(12)
                    ->get(['categories.slug', 'categories.name', DB::raw('count(*) as products_count')])
                    ->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name, 'products_count' => (int) $c->products_count])
                    ->all(),
            ];
        });

        $page->setAttribute('products_count', $supply['products_count']);
        $page->setAttribute('is_indexable', $page->is_indexable && $supply['products_count'] >= self::MIN_INDEXABLE_PRODUCTS);
        if ($withCategories) {
            $page->setAttribute('categories', $supply['categories']);
        }
    }

    /* ── admin CRUD (permission:settings.location_pages.*) ────────── */

    public function adminIndex(Request $request)
    {
        $query = LocationPage::query()
            ->when($request->query('search'), fn ($q, $term) => $q->where('city_name', 'like', "%{$term}%"))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')));

        $orderBy = in_array($request->query('orderBy'), ['city_name', 'updated_at', 'is_active'], true)
            ? $request->query('orderBy') : 'city_name';
        $query->orderBy($orderBy, $request->query('sortedBy') === 'desc' ? 'desc' : 'asc');

        return $query->paginate(min((int) $request->query('limit', 20), 100));
    }

    public function adminShow(int $id)
    {
        return LocationPage::findOrFail($id);
    }

    public function store(Request $request)
    {
        $request->validate([
            'city_id' => 'required|integer|exists:cities,id',
        ] + $this->contentRules());

        $city = City::findOrFail($request->integer('city_id'));
        $slug = Str::slug(AvailabilityService::canonicalCityKey($city->name));
        if ($slug === '') {
            throw new MarvelException('Could not derive a slug for this city.');
        }
        if (LocationPage::where('slug', $slug)->exists()) {
            throw new MarvelException('A landing page for this city already exists.');
        }

        return LocationPage::create($request->only(array_keys($this->contentRules())) + [
            'city_id' => $city->id,
            'slug' => $slug,
            'city_name' => $city->name,
            'state_name' => $city->state_name,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
    }

    public function update(Request $request, int $id)
    {
        $page = LocationPage::findOrFail($id);
        $request->validate($this->contentRules());

        // city/slug are identity, set at creation — content and flags only.
        $page->fill($request->only(array_keys($this->contentRules())));
        $page->updated_by = $request->user()->id;
        $page->save();

        return $page->fresh();
    }

    public function destroy(int $id)
    {
        LocationPage::findOrFail($id)->delete();

        return ['success' => true];
    }

    /** @return array<string, string> shared store/update validation */
    private function contentRules(): array
    {
        return [
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'intro_html' => 'nullable|string|max:65000',
            'delivery_html' => 'nullable|string|max:65000',
            'faqs' => 'nullable|array|max:20',
            'faqs.*.question' => 'required|string|max:500',
            'faqs.*.answer' => 'required|string|max:2000',
            'is_active' => 'sometimes|boolean',
            'is_indexable' => 'sometimes|boolean',
        ];
    }
}
