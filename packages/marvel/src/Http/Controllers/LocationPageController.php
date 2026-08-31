<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
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
    /* ── public (storefront + sitemap) ────────────────────────────── */

    public function index()
    {
        return LocationPage::where('is_active', true)
            ->orderBy('city_name')
            ->get(['slug', 'city_name', 'state_name', 'is_indexable']);
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

        return $page->makeHidden(['created_by', 'updated_by']);
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
