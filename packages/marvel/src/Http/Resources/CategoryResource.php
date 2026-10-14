<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;
use Marvel\Services\CategoryTree;

class CategoryResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        // Root-first chain of primary parents, from the cached hierarchy (no query per row).
        $ancestors = app(CategoryTree::class)->ancestors((int) $this->id);

        return [
            'id'                   => $this->id,
            'name'                 => $this->name,
            'slug'                 => $this->slug,
            'language'             => $this->language,
            'translated_languages' => $this->translated_languages,
            // id + slug as well as the name: the admin form re-submitted `parent` from here and,
            // with only a name, silently turned every edited sub-category into a root. A root
            // keeps an object with null fields, as before, for callers that test `!parent`.
            'parent'               => [
                'id'   => $this->parentCategory->id ?? null,
                'slug' => $this->parentCategory->slug ?? null,
                'name' => $this->parentCategory->name ?? null,
            ],
            'ancestors'            => $ancestors,
            'depth'                => count($ancestors) + 1,
            'display_order'        => (int) ($this->display_order ?? 0),
            'children'             => ChildrenCategoryResource::collection($this->children),
            'products_count'       => $this->products_count,
            'details'              => $this->details,
            'seo_title'            => $this->seo_title ?? null,
            'seo_description'      => $this->seo_description ?? null,
            'noindex'              => (bool) ($this->noindex ?? false),
            'image'                => $this->image,
            'icon'                 => $this->icon,
            'type_id'              => $this->type_id,
            'banner_image'         => $this->banner_image,
            // Homepage placement lives on the category so the person creating one
            // decides it there, instead of it depending on a separate settings edit.
            // Cast explicitly: these arrive from MySQL as 0/1 and the storefront
            // treats them as booleans.
            'show_on_homepage'     => (bool) $this->show_on_homepage,
            'homepage_sort_order'  => (int) $this->homepage_sort_order,
            'is_active'            => (bool) $this->is_active,
            // GST rate inherited by this category's products unless a product
            // sets its own. GstService::categoryTaxRates reads the column
            // directly; exposing it here is what lets the admin form show and
            // edit the current value.
            'tax_rate_id'          => $this->tax_rate_id,
            'type'                 => getResourceData($this->type, []) // if you need extra data then pass key in array by second parameter
        ];
    }
}
