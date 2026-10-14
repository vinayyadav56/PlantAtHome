<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class ChildrenCategoryResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'                   => $this->id,
            'name'                 => $this->name,
            'slug'                 => $this->slug,
            'language'             => $this->language,
            'translated_languages' => $this->translated_languages,
            'products_count'       => $this->products_count,
            'image'                => $this->image,
            'is_active'            => (bool) ($this->is_active ?? true),
            'display_order'        => (int) ($this->display_order ?? 0),
            'parent_id'            => $this->getAttributes()['parent'] ?? null,
            // Listed here as well as under its own parent (one page, every branch).
            'placement'            => (bool) ($this->placement ?? false),
        ];
    }
}
