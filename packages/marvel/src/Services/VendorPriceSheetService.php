<?php

declare(strict_types=1);

namespace Marvel\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Product;

/**
 * Builds the printable vendor price-collection sheet: products down the page, their variants
 * across it, and an empty box in every cell the vendor can actually quote.
 *
 * Three things decide the shape of this, and all three came out of the live catalogue rather
 * than the brief:
 *
 *   1. NOT every product has variants. Tools are `product_type = simple` with no
 *      variation_options at all, so a sheet built purely from variants prints 40 empty rows for
 *      that whole type. Simple products get one PRICE column instead.
 *   2. ONE column set across the whole sheet does not work. Plants are Small/Medium/Large and
 *      pots could be 4"/6"/8"; unioned, every row carries half a table of blank cells and the
 *      page budget is spent on nothing. Rows are grouped, and each group gets its own header
 *      with only the columns its own products use.
 *   3. The variant label is NOT a Size lookup. VariantResolver resolves to the Size attribute
 *      because that is where delivery charges live; it is deliberately not reused here, because
 *      the sheet has to carry Height, Pot Size, Weight, Pack Size or any custom attribute, and a
 *      product may combine several into one variant.
 *
 * Nothing here writes. It is a read model for a piece of paper.
 *
 * It is also the OUTBOUND half of a loop whose inbound half already exists:
 * `VendorPriceSheetImport` reads back `sku | product_id`, `size | variant` and `price`, keyed on
 * (shop, product, variation_option, period). So every row carries a `code` -- the sku, or `#id`
 * when a variable product has none, which is the usual case since the size-pricing commands null
 * the parent sku. Without it a filled-in sheet cannot be typed back in, and the sheet would be a
 * dead end rather than the first step of vendor pricing.
 */
class VendorPriceSheetService
{
    /** The single column a product with no variants gets. */
    public const PRICE_ONLY = '__price__';

    /** A sheet is paper, not a data dump: past this it is not printable and the caller is told. */
    public const MAX_ROWS = 2000;

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query  already filtered by the caller
     * @return array{groups: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function build($query): array
    {
        $products = $query
            ->with(['type:id,name,slug', 'variation_options:id,product_id,title,options,is_disable'])
            ->limit(self::MAX_ROWS + 1)
            ->get(['id', 'name', 'sku', 'product_type', 'type_id']);

        $truncated = $products->count() > self::MAX_ROWS;
        if ($truncated) {
            $products = $products->slice(0, self::MAX_ROWS);
        }

        $order = $this->attributeOrder();
        $groups = [];

        foreach ($products as $product) {
            $labels = $this->variantLabels($product);

            // Group key: the product type, which is what the brief's own example sections are
            // (PLANTS / TOOLS / POTS & PLANTERS) and which in practice is also what shares a
            // variant vocabulary. Products with no variants are split off even within a type,
            // because a price-only row under a Small/Medium/Large header is a lie.
            $typeKey   = $product->type->slug ?? 'other';
            $typeLabel = $product->type->name ?? 'Other';
            $key       = $labels === [] ? $typeKey . ':price-only' : $typeKey;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key'     => $key,
                    'label'   => $typeLabel,
                    'columns' => [],
                    'rows'    => [],
                ];
            }

            $cells = [];
            if ($labels === []) {
                $groups[$key]['columns'][self::PRICE_ONLY] = true;
                $cells[self::PRICE_ONLY] = true;
            } else {
                foreach ($labels as $label) {
                    $groups[$key]['columns'][$label] = true;
                    $cells[$label] = true;
                }
            }

            $groups[$key]['rows'][] = [
                'id'    => (int) $product->id,
                'name'  => (string) $product->name,
                'sku'   => $product->sku ?: null,
                // What a transcriber types into the importer's `sku | product_id` column.
                'code'  => $product->sku ?: ('#' . $product->id),
                'cells' => $cells,
            ];
        }

        $out = [];
        foreach ($groups as $group) {
            $columns = array_keys($group['columns']);
            // Known attribute values sort by their declared sort_order, so a sheet reads
            // Small -> Medium -> Large rather than in whatever order the rows arrived.
            //
            // Everything else keeps FIRST-SEEN order, which is the product's own variant order,
            // and PHP's sort has been stable since 8.0 so returning 0 preserves it. Sorting the
            // unknowns alphabetically instead would print Large, Medium, Small on any catalogue
            // that has not declared a sort_order -- technically ordered, visibly wrong.
            usort($columns, function (string $a, string $b) use ($order) {
                $ra = $order[mb_strtolower($a)] ?? PHP_INT_MAX;
                $rb = $order[mb_strtolower($b)] ?? PHP_INT_MAX;

                return $ra <=> $rb;
            });

            $group['columns'] = array_map(
                fn (string $c) => [
                    'key'   => $c,
                    'label' => $c === self::PRICE_ONLY ? 'Price' : $c,
                ],
                $columns
            );
            $out[] = $group;
        }

        return [
            'groups' => $out,
            'meta'   => [
                'total'     => $products->count(),
                'truncated' => $truncated,
                'max_rows'  => self::MAX_ROWS,
            ],
        ];
    }

    /**
     * The variant labels a vendor is being asked to price, in the product's own order.
     *
     * `variation_options.options` has two shapes in production — admin-built rows carry the
     * attribute_values id, seeded and CLI rows carry only the value text — but BOTH carry
     * `name` and `value`, which is all a label needs. Several attributes on one variant are
     * joined, so a combined variant reads `6 inch / 2-3 ft / Ceramic` rather than losing all
     * but one of its dimensions.
     *
     * @return array<int, string>
     */
    private function variantLabels(Product $product): array
    {
        $labels = [];

        foreach ($product->variation_options as $option) {
            if ((bool) ($option->is_disable ?? false)) {
                continue; // a disabled variant is not for sale, so it is not for pricing
            }

            $parts = [];
            $options = $option->options;
            if (is_string($options)) {
                $options = json_decode($options, true);
            }

            if (is_array($options)) {
                foreach ($options as $pair) {
                    $value = is_array($pair) ? ($pair['value'] ?? null) : null;
                    if (is_scalar($value) && trim((string) $value) !== '') {
                        $parts[] = trim((string) $value);
                    }
                }
            }

            // Fall back to the option's own title: CLI-built rows and anything with an empty or
            // malformed options blob still have one, and a blank column header is useless.
            $label = $parts !== [] ? implode(' / ', $parts) : trim((string) $option->title);
            if ($label !== '' && !in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * Lowercased attribute value => sort_order, so columns print in the catalogue's own order.
     *
     * The Schema check runs per call and is never memoised in a static: deploys migrate AFTER
     * the new code is already serving, so a worker that cached "no such column" would keep
     * mis-ordering every sheet until it recycled.
     *
     * @return array<string, int>
     */
    private function attributeOrder(): array
    {
        if (!Schema::hasTable('attribute_values')) {
            return [];
        }

        $hasSort = Schema::hasColumn('attribute_values', 'sort_order');
        $rows = DB::table('attribute_values')
            ->select(['value', $hasSort ? 'sort_order' : DB::raw('0 as sort_order')])
            ->get();

        $order = [];
        foreach ($rows as $row) {
            $value = mb_strtolower(trim((string) $row->value));
            if ($value !== '' && !isset($order[$value])) {
                $order[$value] = (int) $row->sort_order;
            }
        }

        return $order;
    }
}
