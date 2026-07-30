<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;
use Marvel\Enums\ProductType;

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
            'size_guide'           => $this->size_guide,
            'status'               => $this->status,
            // Single-shop model: who proposed this catalog product (review queue).
            'proposed_by_shop_id'  => $this->proposed_by_shop_id ?? null,
            'proposed_by_shop'     => $this->whenLoaded('proposedByShop', fn () => [
                'id'   => optional($this->proposedByShop)->id,
                'name' => optional($this->proposedByShop)->name,
                'slug' => optional($this->proposedByShop)->slug,
            ]),
            'price'                => $this->price,
            'delivery_charge'      => $this->delivery_charge,
            // Bundles hold no stock of their own — surface the DERIVED quantity so
            // every existing storefront `quantity > 0` stock gate works untouched.
            'quantity'             => $this->product_type === ProductType::BUNDLE
                ? $this->available_bundle_inventory
                : $this->quantity,
            'is_bundle'            => $this->product_type === ProductType::BUNDLE,
            'bundle_type'          => $this->bundle_type,
            'available_inventory'  => $this->product_type === ProductType::BUNDLE
                ? $this->available_bundle_inventory
                : $this->quantity,
            'display_priority'     => $this->display_priority,
            'unit'                 => $this->unit,
            // PlantAtHome — short PLAIN-TEXT description for the listing card.
            // The full HTML `description` is deliberately omitted from list
            // payloads (size), which left cards showing unit/category fallbacks
            // that never matched what the admin wrote. Rendered as text on the
            // storefront (never HTML), so stripping here is also the XSS guard.
            'description_preview'  => $this->descriptionPreview(),
            'sku'                  => $this->sku,
            'barcode'              => $this->barcode ?? null,
            'seo_title'            => $this->seo_title ?? null,
            'seo_description'      => $this->seo_description ?? null,
            'sold_quantity'        => $this->sold_quantity,
            'in_flash_sale'        => $this->in_flash_sale,
            'visibility'           => $this->visibility,
            // PlantAtHome — botanical name + short care chips for the storefront card
            'scientific_name'      => optional($this->plantAttribute)->scientific_name,
            'care'                 => $this->plantCareChips(),
            // PlantAtHome — structured plant attributes for the listing card's
            // feature grid (sunlight / water / height / pet-friendly). Already
            // eager-loaded in fetchProducts(), so no extra query.
            'plant_attribute'      => $this->plantAttribute,
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

    /** Plain-text preview of the (Quill HTML) description, ≤200 chars. */
    protected function descriptionPreview(): ?string
    {
        $raw = (string) ($this->description ?? '');
        if ($raw === '') {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5)));
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > 200 ? mb_substr($text, 0, 200) : $text;
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
