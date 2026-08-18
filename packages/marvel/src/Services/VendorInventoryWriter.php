<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\Schema;
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
     * @param array $opts     ['user_id'=>?, 'period_type'=>'monthly', 'effective_from'=>null, 'effective_to'=>null,
     *                          'skip_existing'=>false]
     *
     * skip_existing: attach-only semantics. An identity (shop+product+variant+period) that
     * already exists is counted as skipped and NEVER updated — the add-from-catalog flow uses
     * this so an accidental duplicate submit cannot silently overwrite a vendor's live price.
     * The price-sheet import and my-inventory edits keep the default upsert.
     * @return array{saved:int, skipped:int, errors:array, items:array}
     */
    public function writeItems(int $shopId, array $items, array $opts = []): array
    {
        $ctx = [
            'periodType'    => $opts['period_type'] ?? 'monthly',
            'effectiveFrom' => $opts['effective_from'] ?? null,
            'effectiveTo'   => $opts['effective_to'] ?? null,
            'userId'        => $opts['user_id'] ?? null,
            'skipExisting'  => (bool) ($opts['skip_existing'] ?? false),
        ];

        $saved = 0;
        $skipped = 0;
        $errors = [];
        $touched = [];
        $savedRows = [];

        foreach (array_values($items) as $i => $item) {
            // A DB error on one row (deadlock, constraint) must NOT abort the batch and 500 with
            // earlier rows already committed — route it to the same per-row error channel as a
            // validation failure so the vendor gets a partial result + a precise skip list.
            try {
                $res = $this->writeOne($shopId, (array) $item, $ctx);
            } catch (\Throwable $e) {
                $res = ['ok' => false, 'error' => 'Could not save this row: ' . $e->getMessage()];
            }
            if ($res['ok']) {
                $saved++;
                $touched[$res['product_id']] = true;
                $savedRows[] = $res['row'];
            } else {
                $skipped++;
                // An attach-only skip is the dedupe rule working, not a failure — it carries no
                // error and must not pollute the error list a vendor reads to fix real rows.
                if (empty($res['skipped']) && count($errors) < 200) {
                    $errors[] = ['index' => $i, 'error' => $res['error'] ?? 'skipped'];
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

        // cost_price is admin-only (the hidden buy price that drives margin + profit). This is the
        // vendor self-serve writer, so we IGNORE any client-supplied cost and require a selling price.
        $sellRaw = $item['vendor_selling_price'] ?? $item['selling_price'] ?? null;
        $sell = is_numeric($sellRaw) ? (float) $sellRaw : null;

        if ($sell === null || $sell <= 0) {
            return ['ok' => false, 'error' => 'A selling price greater than 0 is required.'];
        }

        $values = [
            'is_available'          => true,
            'source'                => $ctx['userId'] ? 'vendor' : 'manual',
            'effective_to'          => $ctx['effectiveTo'],
            'updated_by_user_id'    => $ctx['userId'],
            'deleted_at'            => null, // restore a previously-removed mapping on re-add
            'vendor_selling_price'  => $sell,
        ];
        if (isset($item['stock_qty']) && is_numeric($item['stock_qty'])) {
            $values['stock_qty'] = max(0, (int) $item['stock_qty']);
            // An explicit stock number implies the vendor wants it tracked (so 0 = out of stock)
            // unless they explicitly say otherwise.
            $values['track_stock'] = array_key_exists('track_stock', $item)
                ? (bool) $item['track_stock']
                : true;
        } elseif (array_key_exists('track_stock', $item)) {
            $values['track_stock'] = (bool) $item['track_stock'];
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
        // Per-vendor, per-size logistics. `vendor_sku` (NOT `sku`, which is the
        // master-product lookup key above) maps to the vpp.sku column. Guarded so
        // it's a no-op until the size-fields migration has run.
        if (Schema::hasColumn('vendor_product_prices', 'weight')) {
            if (array_key_exists('vendor_sku', $item) && trim((string) $item['vendor_sku']) !== '') {
                $values['sku'] = trim((string) $item['vendor_sku']);
            }
            if (array_key_exists('barcode', $item) && trim((string) $item['barcode']) !== '') {
                $values['barcode'] = trim((string) $item['barcode']);
            }
            foreach (['weight', 'length', 'breadth', 'height'] as $dim) {
                if (isset($item[$dim]) && is_numeric($item[$dim])) {
                    $values[$dim] = (float) $item[$dim];
                }
            }
        }

        $keys = [
            'shop_id'             => $shopId,
            'product_id'          => $product->id,
            'variation_option_id' => $variationOptionId,
            'period_type'         => $ctx['periodType'],
            'effective_from'      => $ctx['effectiveFrom'],
        ];
        $row = VendorProductPrice::withTrashed()->firstOrNew($keys);
        $isNew = !$row->exists;
        if (!$isNew && $ctx['skipExisting']) {
            // Attach-only: this identity is already the vendor's. Touching nothing is the
            // contract — the row may carry a live price a re-submitted form must not clobber.
            return ['ok' => false, 'skipped' => true, 'product_id' => (int) $product->id, 'row' => $row];
        }
        $row->fill($values);
        if ($isNew) {
            $row->created_by_user_id = $ctx['userId'];
        }
        try {
            $row->save();
        } catch (\Illuminate\Database\QueryException $e) {
            // With the unique index on (shop, product, variant, period, effective_from), a
            // concurrent insert can win the race. Reload the now-existing row and apply our
            // values so the attach stays idempotent instead of throwing a duplicate error —
            // unless this is an attach-only write, where the loser is a duplicate submit and
            // must be SKIPPED, not applied over the winner.
            if ($this->isUniqueViolation($e)) {
                if ($ctx['skipExisting']) {
                    $row = VendorProductPrice::withTrashed()->where($keys)->firstOrFail();
                    return ['ok' => false, 'skipped' => true, 'product_id' => (int) $product->id, 'row' => $row];
                }
                $row = VendorProductPrice::withTrashed()->where($keys)->firstOrFail();
                $row->fill($values);
                $row->save();
            } else {
                throw $e;
            }
        }

        return ['ok' => true, 'product_id' => (int) $product->id, 'row' => $row];
    }

    private function isUniqueViolation(\Throwable $e): bool
    {
        return $e instanceof \Illuminate\Database\QueryException
            && (($e->errorInfo[1] ?? null) === 1062 || (string) $e->getCode() === '23000');
    }
}
