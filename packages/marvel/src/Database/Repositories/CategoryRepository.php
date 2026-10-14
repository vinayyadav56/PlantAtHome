<?php


namespace Marvel\Database\Repositories;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Category;
use Marvel\Http\Requests\CategoryCreateRequest;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;



class CategoryRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name'        => 'like',
        'parent',
        'language',
        'type.slug',
    ];

    /**
     * Write whitelist — saveCategory/updateCategory do $request->only($dataArray),
     * so a column missing from this list is SILENTLY DROPPED. An admin toggle for
     * such a column appears to save and never persists.
     */
    protected $dataArray = [
        'name',
        'slug',
        'type_id',
        'icon',
        'image',
        'banner_image',
        'details',
        'language',
        'parent',
        'show_on_homepage',
        'homepage_sort_order',
        'is_active',
        'seo_title',
        'seo_description',
        'noindex',
        // Position among its siblings (tree endpoint, pills, /tools tiles): lower first.
        'display_order',
        // GST: every product in this category inherits this rate unless it sets
        // its own tax_rate_id. GstService::categoryTaxRates already READS the
        // column — it was just never writable, so rates had to be applied
        // product by product.
        'tax_rate_id',
    ];

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }


    /**
     * Configure the Model
     **/
    public function model()
    {
        return Category::class;
    }

    /**
     * A native <select> submits "" for its empty option, and tax_rate_id is a
     * nullable FK — "" would fail the constraint. Mirrors the same guard in
     * ProductRepository.
     */
    protected function normalizeNullableIds(array $data): array
    {
        foreach (['tax_rate_id'] as $col) {
            if (array_key_exists($col, $data) && is_string($data[$col]) && trim($data[$col]) === '') {
                $data[$col] = null;
            }
        }
        return $data;
    }

    public function saveCategory(Request $request) {
        $data = $this->normalizeNullableIds($request->only($this->dataArray));
        $data['slug'] = $this->resolveSlug($request, null);
        $category = $this->create($data + $this->stamp(true));
        if ($request->has('placements')) {
            $this->syncPlacements((int) $category->id, (array) $request->placements);
        }
        return $category;
    }

    public function updateCategory($request, $category)
    {
        $data = $this->normalizeNullableIds($request->only($this->dataArray));
        if (!empty($request->slug) &&  $request->slug != $category['slug']) {
            $data['slug'] = $this->resolveSlug($request, (int) $category->id);
        }
        $category->update($data + $this->stamp(false));
        if ($request->has('placements')) {
            $this->syncPlacements((int) $category->id, (array) $request->placements);
        }
        return $this->findOrFail($category->id);
    }

    /**
     * The other parents a category is listed under: exactly the given list (missing ones added
     * after that parent's own children, dropped ones removed). Navigation and roll-up only — the
     * category keeps its one primary parent, URL and breadcrumb.
     */
    public function syncPlacements(int $categoryId, array $parentIds): void
    {
        if (!Schema::hasTable('category_placements')) {
            return;
        }
        $primary = (int) DB::table('categories')->where('id', $categoryId)->value('parent');
        $wanted = collect($parentIds)->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0 && $id !== $categoryId && $id !== $primary)
            ->unique()->values()->all();

        DB::table('category_placements')->where('category_id', $categoryId)->whereNotIn('parent_id', $wanted)->delete();
        foreach ($wanted as $parentId) {
            if (DB::table('category_placements')->where('category_id', $categoryId)->where('parent_id', $parentId)->exists()) {
                continue;
            }
            $last = max(
                (int) DB::table('categories')->where('parent', $parentId)->max('display_order'),
                (int) DB::table('category_placements')->where('parent_id', $parentId)->max('display_order')
            );
            DB::table('category_placements')->insert([
                'category_id' => $categoryId, 'parent_id' => $parentId, 'display_order' => $last + 10,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * A category's slug is its URL (/c/{slug}). A slug the admin typed that is already taken is
     * refused (422) instead of silently getting a random suffix (globalSlugify); a slug made
     * from the name gets -2, -3… so it stays predictable.
     */
    private function resolveSlug(Request $request, ?int $selfId): string
    {
        $language = $request->language ?: DEFAULT_LANGUAGE;
        $explicit = trim((string) $request->slug) !== '';
        $base = Str::slug($explicit ? (string) $request->slug : (string) $request->name);
        $taken = fn (string $slug) => Category::query()->where('slug', $slug)->where('language', $language)
            ->when($selfId, fn ($q) => $q->where('id', '!=', $selfId))->exists();
        if (!$taken($base)) {
            return $base;
        }
        if ($explicit) {
            throw new HttpResponseException(response()->json([
                'slug' => ["The address \"{$base}\" is already used by another category. Choose another."],
            ], 422));
        }
        for ($i = 2; $taken("{$base}-{$i}"); $i++);

        return "{$base}-{$i}";
    }

    /** Who created / last edited the category (the tools taxonomy relabel never overrides an admin edit). */
    private function stamp(bool $creating): array
    {
        $userId = optional(request()->user())->id;
        if (!$userId) {
            return [];
        }
        $out = [];
        if (Schema::hasColumn('categories', 'updated_by_user_id')) {
            $out['updated_by_user_id'] = $userId;
        }
        if ($creating && Schema::hasColumn('categories', 'created_by_user_id')) {
            $out['created_by_user_id'] = $userId;
        }
        return $out;
    }
}
