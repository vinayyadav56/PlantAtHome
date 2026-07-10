<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Infrastructure\Models\Category;
use App\Shared\Application\DomainActionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Category tree use-cases. Maintains the materialized `path` (ancestor ids) and
 * `depth` so subtree queries are a single indexed LIKE. Admin-owned.
 */
class CategoryService
{
    public function create(array $data, ?string $actorUuid = null): Category
    {
        return DB::transaction(function () use ($data, $actorUuid) {
            $parent = null;
            if (! empty($data['parent_uuid'])) {
                $parent = Category::byUuid($data['parent_uuid'])->first();
                if (! $parent) {
                    throw DomainActionException::unprocessable('Parent category not found.', 'PARENT_NOT_FOUND', 'parent_uuid');
                }
            }

            $category = new Category([
                'name'            => $data['name'],
                'slug'            => $this->uniqueSlug($data['slug'] ?? $data['name']),
                'sort'            => $data['sort'] ?? 0,
                'status'          => $data['status'] ?? 'active',
                'seo_title'       => $data['seo_title'] ?? null,
                'seo_description' => $data['seo_description'] ?? null,
                'parent_id'       => $parent?->id,
                'depth'           => $parent ? $parent->depth + 1 : 0,
                'created_by'      => $actorUuid,
                'updated_by'      => $actorUuid,
            ]);
            // Materialized path of ANCESTOR ids (self excluded; self-id lives on
            // the row). Root: "/". Child of id=1: "/1/". Grandchild: "/1/5/".
            $category->path = $parent ? $parent->path.$parent->id.'/' : '/';
            $category->save();

            return $category;
        });
    }

    public function update(Category $category, array $data, ?string $actorUuid = null): Category
    {
        $category->fill(array_filter([
            'name'            => $data['name'] ?? null,
            'sort'            => $data['sort'] ?? null,
            'status'          => $data['status'] ?? null,
            'seo_title'       => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
        ], fn ($v) => $v !== null));

        if (! empty($data['slug']) && $data['slug'] !== $category->slug) {
            $category->slug = $this->uniqueSlug($data['slug'], $category->id);
        }

        $category->updated_by = $actorUuid;
        $category->save();

        return $category;
    }

    /** A unique slug derived from the given base, ignoring $ignoreId's own row. */
    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = Str::slug($base);
        if ($slug === '') {
            $slug = 'category';
        }

        $candidate = $slug;
        $n = 1;
        while (Category::withTrashed()
            ->where('slug', $candidate)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $candidate = $slug.'-'.(++$n);
        }

        return $candidate;
    }
}
