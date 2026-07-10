<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Infrastructure\Models\Product;

final class ProductResource
{
    public static function make(Product $p): array
    {
        return [
            'uuid'            => $p->uuid,
            'name'            => $p->name,
            'slug'            => $p->slug,
            'botanical_name'  => $p->botanical_name,
            'hindi_name'      => $p->hindi_name,
            'description'     => $p->description,
            'care'            => $p->care,
            'status'          => $p->status,
            'published_at'    => $p->published_at?->toIso8601String(),
            'seo_title'       => $p->seo_title,
            'seo_description' => $p->seo_description,
            'category'        => $p->relationLoaded('category') && $p->category
                ? ['uuid' => $p->category->uuid, 'name' => $p->category->name, 'slug' => $p->category->slug]
                : null,
            'variants' => $p->relationLoaded('variants')
                ? $p->variants->map(fn ($v) => VariantResource::make($v))->all()
                : null,
            'media' => $p->relationLoaded('media')
                ? $p->media->map(fn ($m) => [
                    'uuid' => $m->uuid, 'url' => $m->url, 'type' => $m->type, 'alt' => $m->alt, 'sort' => $m->sort,
                ])->all()
                : null,
            'attributes' => $p->relationLoaded('attributeValues')
                ? $p->attributeValues->map(fn ($av) => [
                    'code'  => $av->relationLoaded('attribute') ? $av->attribute?->code : null,
                    'value' => is_array($av->value) ? ($av->value['value'] ?? null) : $av->value,
                ])->all()
                : null,
        ];
    }
}
