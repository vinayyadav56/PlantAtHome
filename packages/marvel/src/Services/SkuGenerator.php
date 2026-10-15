<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Variation;

/**
 * Generates unique, human-readable SKUs for products and their variations.
 *
 * Format: {VERTICAL}-{CATEGORY}-{SEQ}[-{VARIANT}]
 *   PLT-SUC-00042        (Plants > Succulents, product #42)
 *   PLT-SUC-00042-M      (… Medium variation: the Size value's code)
 *   TLS-PRU-00007-RED-5L (Tools > Pruning, a Colour × Capacity variant)
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

    /** Per product id: [lower attribute name \0 lower value => code]. */
    private array $codes = [];

    public function forProduct(Product $product): string
    {
        $vertical = $this->verticalCode($product);
        $category = $this->categoryCode($product);
        $seq      = str_pad((string) $product->id, 5, '0', STR_PAD_LEFT);

        return "{$vertical}-{$category}-{$seq}";
    }

    /**
     * Variation SKU = product SKU + one part per option value, joined with '-': the value's
     * attribute_values.code when it has one (S/M/L), else its first 4 letters/digits. One part per
     * axis keeps "Red/5 L" and "Red/50 L" apart; a SKU another variant holds gets "-{id}".
     */
    public function forVariation(Product $product, Variation $variation): string
    {
        $base = $product->sku ?: $this->forProduct($product);
        $codes = $this->valueCodes($product);
        $parts = [];
        foreach ((array) $variation->options as $option) {
            $name = data_get($option, 'name');
            $value = data_get($option, 'value');
            if (is_scalar($value)) {
                $key = mb_strtolower(trim((string) $name)) . "\0" . mb_strtolower(trim((string) $value));
                $parts[] = $codes[$key] ?? $this->variantCode((string) $value);
            }
        }
        $suffix = implode('-', array_filter($parts)) ?: ($this->variantCode((string) $variation->title) ?: 'V' . $variation->id);
        $sku = "{$base}-{$suffix}";

        return Variation::where('sku', $sku)->where('id', '!=', $variation->id)->exists() ? "{$sku}-{$variation->id}" : $sku;
    }

    /** Fills every variant of the product without a SKU (every variant with $all); returns how many. */
    public function fillVariantSkus(Product $product, bool $all = false): int
    {
        $query = $product->variation_options()->orderBy('id');
        if (!$all) {
            $query->where(fn ($q) => $q->whereNull('sku')->orWhere('sku', ''));
        }
        $filled = 0;
        foreach ($query->get() as $variation) {
            $variation->sku = $this->forVariation($product, $variation);
            $variation->saveQuietly();
            $filled++;
        }

        return $filled;
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

    /** The codes of the attribute values this product is linked to (Size: S/M/L). */
    private function valueCodes(Product $product): array
    {
        if (!isset($this->codes[$product->id])) {
            $this->codes[$product->id] = [];
            try {
                $rows = DB::table('attribute_product')
                    ->join('attribute_values', 'attribute_values.id', '=', 'attribute_product.attribute_value_id')
                    ->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
                    ->where('attribute_product.product_id', $product->id)
                    ->whereNotNull('attribute_values.code')
                    ->get(['attributes.name', 'attribute_values.value', 'attribute_values.code']);
            } catch (\Throwable $e) {
                $rows = []; // no code column yet: every part falls back to the value's letters
            }
            foreach ($rows as $r) {
                if (trim((string) $r->code) !== '') {
                    $this->codes[$product->id][mb_strtolower(trim((string) $r->name)) . "\0" . mb_strtolower(trim((string) $r->value))] = trim((string) $r->code);
                }
            }
        }

        return $this->codes[$product->id];
    }

    /** First 4 letters/digits, upper-cased ('' when there are none). */
    private function variantCode(string $text): string
    {
        return substr(strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $text)), 0, 4);
    }
}
