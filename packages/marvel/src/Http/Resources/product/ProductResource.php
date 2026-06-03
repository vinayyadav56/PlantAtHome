<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class ProductResource extends Resource
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
            'type'                 => getResourceData($this->type, ['settings']), // if you need extra data then pass key in array by second parameter
            'language'             => $this->language,
            'translated_languages' => $this->translated_languages,
            'product_type'         => $this->product_type,
            'shop'                 => getResourceData($this->shop, []), // if you need extra data then pass key in array by second parameter
            'sale_price'           => $this->sale_price,
            'max_price'            => $this->max_price,
            'min_price'            => $this->min_price,
            'image'                => $this->image,
            'status'               => $this->status,
            'price'                => $this->price,
            'quantity'             => $this->quantity,
            'unit'                 => $this->unit,
            'sku'                  => $this->sku,
            'sold_quantity'        => $this->sold_quantity,
            'in_flash_sale'        => $this->in_flash_sale,
            'visibility'           => $this->visibility,
            // PlantAtHome — botanical name + short care chips for the storefront card
            'scientific_name'      => optional($this->plantAttribute)->scientific_name,
            'care'                 => $this->plantCareChips(),
            // PlantAtHome — bundle support (only meaningful for product_type=bundle)
            'bundle_items'         => $this->whenLoaded('bundleItems', fn () => static::mapInclusions($this->bundleItems)),
            'bundle_total_value'   => $this->relationLoaded('bundleItems') ? $this->bundle_total_value : null,
        ];
    }

    /** Compact representation of included/add-on products. */
    public static function mapInclusions($items): array
    {
        return collect($items)->map(fn ($p) => [
            'id'         => $p->id,
            'name'       => $p->name,
            'slug'       => $p->slug,
            'image'      => $p->image,
            'price'      => $p->price,
            'sale_price' => $p->sale_price,
            'min_price'  => $p->min_price, // variable children: NULL price → shop falls back to this
            'quantity'   => (int) (optional($p->pivot)->quantity ?: 1),
        ])->values()->all();
    }

    /** Up to three short care chips (Light · Water · Ease) from plant_attribute. */
    protected function plantCareChips(): array
    {
        $pa = $this->plantAttribute;
        if (!$pa) {
            return [];
        }

        $chips = [];

        $sun = strtolower((string) $pa->sunlight);
        if ($sun !== '') {
            // order matters: check indirect/bright before full/direct so
            // "bright indirect" isn't matched as "full sun" (indirect ⊃ direct)
            if (preg_match('/low light|shade/', $sun))                       $chips[] = 'Low light';
            elseif (preg_match('/bright|indirect|partial|filtered/', $sun))  $chips[] = 'Bright';
            elseif (preg_match('/full sun|direct/', $sun))                   $chips[] = 'Full sun';
            else                                                             $chips[] = ucfirst(strtok($sun, ' '));
        }

        $water = strtolower((string) $pa->water_requirement);
        if ($water !== '') {
            if (preg_match('/low|drought|month/', $water))   $chips[] = 'Monthly';
            elseif (preg_match('/high|daily|moist/', $water)) $chips[] = 'Frequent';
            else                                              $chips[] = 'Weekly';
        }

        $chips[] = 'Easy';

        return array_values(array_filter(array_slice($chips, 0, 3)));
    }
}
