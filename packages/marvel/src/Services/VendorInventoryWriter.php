<?php

namespace Marvel\Services;

use Marvel\Database\Models\Product;
use Marvel\Database\Models\Variation;
use Marvel\Database\Models\VendorProductPrice;

/**
 * Canonical writer for vendor → master-product inventory mappings (the self-serve
 * "Add Products" path). Vendors NEVER create products; they only attach a selling
 * price + stock to existing master `products`. Every row is forced to the caller's
 * own shop_id (set by the controller, never trusted from input), keyed standing
 * (period_type=monthly, effective_from=null) so each vendor has one current row per
 * product+variant. Recomputes the city-availability projection for touched products.
 *
 * Mirrors VendorPriceSheetImport's row semantics so the Excel and UI paths agree.
 */
class VendorInventoryWriter
{
    /**
     * @param int   $shopId  the vendor's shop (authoritative — set by the controller)
     * @param array $items   [{product_id|sku, variation_option_id|size, vendor_selling_price, cost_price?, stock_qty?, fulfillment_mode?, moq?, lead_time_days?}]
     * @param array $opts     ['user_id'=>?, 'period_type'=>'monthly', 'effective_from'=>null, 'effective_to'=>null]
     * @return array{saved:int, skipped:int, errors:array, items:array}
     */
    public function writeItems(int $shopId, array $items, array $opts = []): array
    {
        $ctx = [
            'periodType'    => $opts['period_type'] ?? 'monthly',
            'effectiveFrom' => $opts['effective_from'] ?? null,
            'effectiveTo'   => $opts['effective_to'] ?? null,
            'userId'        => $opts['user_id'] ?? null,
        ];

        $saved = 0;
        $skipped = 0;
        $errors = [];
        $touched = [];
        $savedRows = [];

        foreach (array_values($items) as $i => $item) {
            $res = $this->writeOne($shopId, (array) $item, $ctx);
            if ($res['ok']) {
                $saved++;
                $touched[$res['product_id']] = true;
                $savedRows[] = $res['row'];
            } else {
                $skipped++;
                if (count($errors) < 200) {
                    $errors[] = ['index' => $i, 'error' => $res['error']];
                }
            }
        }

        // Refresh the city-availability projection for every product we touched.
        $availability = new AvailabilityService();
        foreach (array_keys($touched) as $pid) {
            $availability->recomputeForProduct((int) $pid);
        }
        if (!empty($touched)) {
            AvailabilityService::bustCatalogCache();
        }

        return ['saved' => $saved, 'skipped' => $skipped, 'errors' => $errors, 'items' => $savedRows];
    }

    /** @return array{ok:bool, error?:string, product_id?:int, row?:VendorProductPrice} */
    private function writeOne(int $shopId, array $item, array $ctx): array
    {
        $productId = $item['product_id'] ?? null;
        $sku = isset($item['sku']) ? trim((string) $item['sku']) : null;

        $product = $productId
            ? Product::find((int) $productId)
            : ($sku ? Product::where('sku', $sku)->first() : null);
        if (!$product) {
            return ['ok' => false, 'error' => 'Master product not found (' . ($productId ?: $sku ?: '?') . ').'];
        }

        // Resolve the variant (by id, or by title/sku), scoped to THIS product.
        $variationOptionId = null;
        if (!empty($item['variation_option_id'])) {
            $vo = Variation::where('product_id', $product->id)->where('id', (int) $item['variation_option_id'])->first();
            if (!$vo) {
                return ['ok' => false, 'error' => "Variant not found for product {$product->id}."];
            }
            $variationOptionId = $vo->id;
        } elseif (!empty($item['size'])) {
            $size = trim((string) $item['size']);
            $vo = Variation::where('product_id', $product->id)
                ->where(fn ($q) => $q->where('title', $size)->orWhere('sku', $size))->first();
            if (!$vo) {
                return ['ok' => false, 'error' => "Size '{$size}' not found for product {$product->id}."];
            }
            $variationOptionId = $vo->id;
        }

        $sellRaw = $item['vendor_selling_price'] ?? $item['selling_price'] ?? null;
        $costRaw = $item['cost_price'] ?? $item['cost'] ?? null;
        $sell = is_numeric($sellRaw) ? (float) $sellRaw : null;
        $cost = is_numeric($costRaw) ? (float) $costRaw : null;

        if ($sell !== null && $sell < 0) {
            return ['ok' => false, 'error' => 'Selling price must be a positive number.'];
        }
        if ($cost !== null && $cost < 0) {
            return ['ok' => false, 'error' => 'Cost must be a positive number.'];
        }
        if (($sell === null || $sell <= 0) && ($cost === null || $cost <= 0)) {
            return ['ok' => false, 'error' => 'A selling price (or cost) greater than 0 is required.'];
        }

        $values = [
            'is_available'       => ($sell !== null && $sell > 0) || ($cost !== null && $cost > 0),
            'source'             => $ctx['userId'] ? 'vendor' : 'manual',
            'effective_to'       => $ctx['effectiveTo'],
            'updated_by_user_id' => $ctx['userId'],
            'deleted_at'         => null, // restore a previously-removed mapping on re-add
        ];
        if ($sell !== null) {
            $values['vendor_selling_price'] = $sell;
        }
        if ($cost !== null) {
            $values['cost_price'] = $cost;
        }
        if (isset($item['stock_qty']) && is_numeric($item['stock_qty'])) {
            $values['stock_qty'] = max(0, (int) $item['stock_qty']);
        }
        $mode = strtolower(trim((string) ($item['fulfillment_mode'] ?? '')));
        if (in_array($mode, ['local', 'courier', 'both'], true)) {
            $values['fulfillment_mode'] = $mode;
        }
        if (isset($item['moq']) && is_numeric($item['moq'])) {
            $values['moq'] = max(1, (int) $item['moq']);
        }
        if (isset($item['lead_time_days']) && is_numeric($item['lead_time_days'])) {
            $values['lead_time_days'] = max(0, (int) $item['lead_time_days']);
        }

        $row = VendorProductPrice::withTrashed()->firstOrNew([
            'shop_id'             => $shopId,
            'product_id'          => $product->id,
            'variation_option_id' => $variationOptionId,
            'period_type'         => $ctx['periodType'],
            'effective_from'      => $ctx['effectiveFrom'],
        ]);
        $isNew = !$row->exists;
        $row->fill($values);
        if ($isNew) {
            $row->created_by_user_id = $ctx['userId'];
        }
        $row->save();

        return ['ok' => true, 'product_id' => (int) $product->id, 'row' => $row];
    }
}
