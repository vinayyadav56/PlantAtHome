<?php

namespace Marvel\Services;

use Marvel\Database\Models\Product;

/**
 * Generates unique, human-readable SKUs for products and their variations.
 *
 * Format: {VERTICAL}-{CATEGORY}-{SEQ}[-{VARIANT}]
 *   PLT-SUC-00042        (Plants > Succulents, product #42)
 *   PLT-SUC-00042-M      (… Medium variation)
 *   TLS-PRU-00007        (Tools > Pruning)
 *   FBX-VEG-00103        (FarmBox > Vegetables)
 *
 * SEQ is the immutable product id (zero-padded) → guaranteed unique with no
 * counter/race. Vertical comes from the product Type, category from the product's
 * first category. Deterministic + idempotent.
 */
class SkuGenerator
{
    /** Known vertical (Type slug) → 3-letter code. */
    private const VERTICAL = [
        'plants'  => 'PLT',
        'tools'   => 'TLS',
        'farmbox' => 'FBX',
    ];

    public function forProduct(Product $product): string
    {
        $vertical = $this->verticalCode($product);
        $category = $this->categoryCode($product);
        $seq      = str_pad((string) $product->id, 5, '0', STR_PAD_LEFT);

        return "{$vertical}-{$category}-{$seq}";
    }

    /** Variation SKU = product SKU + a short variant suffix (option title or id). */
    public function forVariation(Product $product, $variationOption, int $index = 0): string
    {
        $base = $product->sku ?: $this->forProduct($product);
        $title = is_object($variationOption)
            ? ($variationOption->title ?? '')
            : ($variationOption['title'] ?? '');
        $suffix = $this->variantCode($title, $index);

        return "{$base}-{$suffix}";
    }

    private function verticalCode(Product $product): string
    {
        $slug = optional($product->type)->slug ?? '';
        if (isset(self::VERTICAL[$slug])) {
            return self::VERTICAL[$slug];
        }
        $alpha = strtoupper(preg_replace('/[^a-zA-Z]/', '', $slug));
        return substr($alpha . 'GEN', 0, 3);
    }

    private function categoryCode(Product $product): string
    {
        $category = $product->relationLoaded('categories')
            ? $product->categories->first()
            : $product->categories()->first();
        $slug  = $category ? $category->slug : 'gen';
        $alpha = strtoupper(preg_replace('/[^a-zA-Z]/', '', $slug));
        return substr($alpha . 'GEN', 0, 3);
    }

    private function variantCode(string $title, int $index): string
    {
        $clean = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $title));
        if ($clean === '') {
            return 'V' . ($index + 1);
        }
        return substr($clean, 0, 4);
    }
}
