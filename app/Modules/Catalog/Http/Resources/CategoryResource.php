<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Infrastructure\Models\Category;

final class CategoryResource
{
    public static function make(Category $c): array
    {
        return [
            'uuid'            => $c->uuid,
            'name'            => $c->name,
            'slug'            => $c->slug,
            // `path` (internal ancestor ids) is deliberately NOT exposed — UUIDs
            // only over the wire. depth + parent_uuid convey tree position.
            'depth'           => $c->depth,
            'sort'            => $c->sort,
            'status'          => $c->status,
            'parent_uuid'     => $c->relationLoaded('parent') ? $c->parent?->uuid : null,
            'seo_title'       => $c->seo_title,
            'seo_description' => $c->seo_description,
            'children'        => $c->relationLoaded('children')
                ? $c->children->map(fn (Category $child) => self::make($child))->all()
                : null,
        ];
    }
}
