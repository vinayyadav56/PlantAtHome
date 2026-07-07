<?php

namespace Marvel\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Variation;
use Marvel\Database\Models\VendorProductPrice;
use Marvel\Services\AvailabilityService;

/**
 * Parses a vendor price-sheet (.xlsx/.csv). The vendor (shop), period and effective
 * dates are chosen at upload; each row carries the product + optional size + price
 * (+ optional stock / fulfillment / delivery charge).
 *
 * Expected headings (case/space-insensitive via WithHeadingRow):
 *   sku | product_id        (one required — identifies the master product)
 *   size | variant          (optional — variation_option title or sku)
 *   price | selling_price    (vendor's ₹ selling price — the customer-facing price)
 *   cost_price | cost        (ADMIN sheets only — ignored on vendor self-serve uploads)
 *   inventory | stock | stock_qty (optional — per-vendor stock; sets track_stock)
 *   fulfillment_mode         (optional — local | courier | both)
 *   delivery_charge          (optional — sets products.delivery_charge)
 *
 * Rows upsert into vendor_product_prices keyed by
 * (shop_id, product_id, variation_option_id, period_type, effective_from).
 *
 * Robustness: chunk-read so a huge sheet can't exhaust memory; each row is wrapped so a
 * single malformed/failing row is skipped (with a precise line number) instead of aborting
 * the whole import after committing earlier rows.
 */
class VendorPriceSheetImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    public int $rowCount = 0;
    public int $errorCount = 0;
    public array $errors = [];
    /** Running count of data rows seen across chunks, so line numbers stay absolute. */
    private int $rowOffset = 0;

    public function __construct(
        private int $shopId,
        private string $periodType,
        private ?string $effectiveFrom,
        private ?string $effectiveTo,
        private int $batchId,
        private ?int $userId = null,
        /** Admin sheets may set the hidden cost_price; vendor self-serve uploads never can. */
        private bool $allowCost = true
    ) {
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function collection(Collection $rows): void
    {
        $base = $this->rowOffset;
        $touched = [];

        foreach ($rows as $i => $row) {
            $line = $base + $i + 2; // +1 heading, +1 to 1-index
            try {
                if ($this->importRow($row, $line, $touched)) {
                    $this->rowCount++;
                }
            } catch (\Throwable $e) {
                // A genuine DB error on one row must not abort the whole import — skip it.
                $this->fail($line, 'Could not import this row: ' . $e->getMessage());
            }
        }

        $this->rowOffset += $rows->count();

        // Refresh the city-availability projection for the products this chunk touched.
        $availability = new AvailabilityService();
        foreach (array_keys($touched) as $pid) {
            $availability->recomputeForProduct((int) $pid);
        }
        if (!empty($touched)) {
            AvailabilityService::bustCatalogCache();
        }
    }

    /** @return bool true when a row was imported, false when it was skipped (via fail()). */
    private function importRow(Collection $row, int $line, array &$touched): bool
    {
        $sku       = $this->str($row, 'sku');
        $productId = $this->str($row, 'product_id') ?: $this->str($row, 'id');
        $size      = $this->str($row, 'size') ?: $this->str($row, 'variant');
        $sellRaw   = $row->get('selling_price', $row->get('price', null));
        // cost_price is admin-only; a vendor self-serve upload never sets it (margin/profit integrity).
        $costRaw   = $this->allowCost ? $row->get('cost_price', $row->get('cost', null)) : null;

        if (!$sku && !$productId) {
            $this->fail($line, 'Missing sku / product_id.');
            return false;
        }
        $cost = is_numeric($costRaw) ? (float) $costRaw : null;
        $sell = is_numeric($sellRaw) ? (float) $sellRaw : null;
        if ($this->allowCost && $costRaw !== null && $costRaw !== '' && $cost === null) {
            $this->fail($line, "Invalid cost_price '{$costRaw}'.");
            return false;
        }
        if ($sellRaw !== null && $sellRaw !== '' && $sell === null) {
            $this->fail($line, "Invalid price '{$sellRaw}'.");
            return false;
        }
        if ($cost !== null && $cost < 0) {
            $this->fail($line, "Invalid cost_price '{$costRaw}'.");
            return false;
        }
        if ($sell !== null && $sell < 0) {
            $this->fail($line, "Invalid price '{$sellRaw}'.");
            return false;
        }
        if ($cost === null && $sell === null) {
            $this->fail($line, $this->allowCost ? 'Provide a price (or cost_price).' : 'Provide a price.');
            return false;
        }

        $product = $productId
            ? Product::find((int) $productId)
            : Product::where('sku', $sku)->first();
        if (!$product) {
            $this->fail($line, 'Product not found (' . ($sku ?: $productId) . ').');
            return false;
        }

        // Resolve size → variation_option scoped to this product.
        $variationOptionId = null;
        if ($size) {
            $vo = Variation::where('product_id', $product->id)
                ->where(function ($q) use ($size) {
                    $q->where('title', $size)->orWhere('sku', $size);
                })->first();
            if (!$vo) {
                $this->fail($line, "Size '{$size}' not found for product {$product->id}.");
                return false;
            }
            $variationOptionId = $vo->id;
        }

        // Only set columns that are present, so a price-only sheet never wipes an
        // existing cost (and vice-versa).
        $values = [
            'effective_to'       => $this->effectiveTo,
            'source'             => 'excel',
            'import_batch_id'    => $this->batchId,
            'updated_by_user_id' => $this->userId,
            'deleted_at'         => null, // re-uploading restores a previously-removed mapping
        ];
        if ($cost !== null) {
            $values['cost_price'] = $cost;
        }
        if ($sell !== null) {
            $values['vendor_selling_price'] = $sell;
        }
        $stockRaw = $row->get('stock_qty', $row->get('stock', $row->get('inventory', null)));
        if (is_numeric($stockRaw)) {
            $values['stock_qty'] = max(0, (int) $stockRaw);
            // A stock number on the sheet means the vendor is tracking stock (0 = out of stock).
            $values['track_stock'] = true;
        }
        $modeRaw = strtolower($this->str($row, 'fulfillment_mode') ?: $this->str($row, 'fulfillment'));
        if (in_array($modeRaw, ['local', 'courier', 'both'], true)) {
            $values['fulfillment_mode'] = $modeRaw;
        }

        // firstOrNew(withTrashed) so a re-upload RESTORES a soft-deleted row in place (matches
        // VendorInventoryWriter — no orphaned duplicates); is_available is derived from the MERGED
        // row state so a partial sheet can't hide a still-priced row. A concurrent insert winning
        // the new unique index is reloaded and updated rather than throwing.
        $keys = [
            'shop_id'             => $this->shopId,
            'product_id'          => $product->id,
            'variation_option_id' => $variationOptionId,
            'period_type'         => $this->periodType,
            'effective_from'      => $this->effectiveFrom,
        ];
        $vpp = VendorProductPrice::withTrashed()->firstOrNew($keys);
        $isNew = !$vpp->exists;
        $vpp->fill($values);
        $vpp->is_available = (float) ($vpp->vendor_selling_price ?? 0) > 0 || (float) ($vpp->cost_price ?? 0) > 0;
        if ($isNew) {
            $vpp->created_by_user_id = $this->userId;
        }
        try {
            $vpp->save();
        } catch (\Illuminate\Database\QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 || (string) $e->getCode() === '23000') {
                $vpp = VendorProductPrice::withTrashed()->where($keys)->firstOrFail();
                $vpp->fill($values);
                $vpp->is_available = (float) ($vpp->vendor_selling_price ?? 0) > 0 || (float) ($vpp->cost_price ?? 0) > 0;
                $vpp->save();
            } else {
                throw $e;
            }
        }
        $touched[$product->id] = true;

        // Optional per-product delivery charge on the same sheet (admin content only).
        $deliveryCharge = $row->get('delivery_charge', null);
        if ($this->allowCost && $deliveryCharge !== null && $deliveryCharge !== '' && is_numeric($deliveryCharge)) {
            $product->update(['delivery_charge' => (float) $deliveryCharge]);
        }

        return true;
    }

    private function fail(int $line, string $msg): void
    {
        $this->errorCount++;
        if (count($this->errors) < 50) {
            $this->errors[] = ['line' => $line, 'error' => $msg];
        }
    }

    private function str(Collection $row, string $key): string
    {
        $v = $row->get($key);
        return $v === null ? '' : trim((string) $v);
    }
}
