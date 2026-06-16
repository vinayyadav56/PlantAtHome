<?php

namespace Marvel\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Variation;
use Marvel\Database\Models\VendorProductPrice;
use Marvel\Services\AvailabilityService;

/**
 * Parses an admin vendor price-sheet (.xlsx/.csv). The vendor (shop), period and
 * effective dates are chosen at upload; each row carries the product + optional
 * size + cost (+ optional per-product delivery charge).
 *
 * Expected headings (case/space-insensitive via WithHeadingRow):
 *   sku | product_id        (one required — identifies the master product)
 *   size | variant          (optional — variation_option title or sku)
 *   price | selling_price    (vendor's ₹ selling price — the customer-facing price)
 *   cost_price | cost        (optional — admin cost-sheet / vendor margin reference)
 *   inventory | stock | stock_qty (optional — per-vendor stock)
 *   fulfillment_mode         (optional — local | courier | both)
 *   delivery_charge          (optional — sets products.delivery_charge)
 *
 * At least one of price / cost_price must be a positive number. A row with neither a
 * positive price nor cost is flagged available=false (orderable, "available in 6h").
 * Rows upsert into vendor_product_prices keyed by
 * (shop_id, product_id, variation_option_id, period_type, effective_from).
 */
class VendorPriceSheetImport implements ToCollection, WithHeadingRow
{
    public int $rowCount = 0;
    public int $errorCount = 0;
    public array $errors = [];
    private array $touchedProducts = [];

    public function __construct(
        private int $shopId,
        private string $periodType,
        private ?string $effectiveFrom,
        private ?string $effectiveTo,
        private int $batchId,
        private ?int $userId = null
    ) {
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $line = $i + 2; // +1 heading, +1 to 1-index
            $sku       = $this->str($row, 'sku');
            $productId = $this->str($row, 'product_id') ?: $this->str($row, 'id');
            $size      = $this->str($row, 'size') ?: $this->str($row, 'variant');
            // Admin cost sheets carry cost_price; vendor sheets carry a selling `price`
            // (+ `inventory`). Either is accepted; at least one must be a positive number.
            $costRaw   = $row->get('cost_price', $row->get('cost', null));
            $sellRaw   = $row->get('selling_price', $row->get('price', null));

            if (!$sku && !$productId) {
                $this->fail($line, 'Missing sku / product_id.');
                continue;
            }
            $cost = is_numeric($costRaw) ? (float) $costRaw : null;
            $sell = is_numeric($sellRaw) ? (float) $sellRaw : null;
            if ($costRaw !== null && $costRaw !== '' && $cost === null) {
                $this->fail($line, "Invalid cost_price '{$costRaw}'.");
                continue;
            }
            if ($sellRaw !== null && $sellRaw !== '' && $sell === null) {
                $this->fail($line, "Invalid price '{$sellRaw}'.");
                continue;
            }
            if ($cost !== null && $cost < 0) {
                $this->fail($line, "Invalid cost_price '{$costRaw}'.");
                continue;
            }
            if ($sell !== null && $sell < 0) {
                $this->fail($line, "Invalid price '{$sellRaw}'.");
                continue;
            }
            if ($cost === null && $sell === null) {
                $this->fail($line, 'Provide a price (or cost_price).');
                continue;
            }

            $product = $productId
                ? Product::find((int) $productId)
                : Product::where('sku', $sku)->first();
            if (!$product) {
                $this->fail($line, 'Product not found (' . ($sku ?: $productId) . ').');
                continue;
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
                    continue;
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
            }
            $modeRaw = strtolower($this->str($row, 'fulfillment_mode') ?: $this->str($row, 'fulfillment'));
            if (in_array($modeRaw, ['local', 'courier', 'both'], true)) {
                $values['fulfillment_mode'] = $modeRaw;
            }

            // firstOrNew(withTrashed) so a re-upload RESTORES a soft-deleted row in place
            // (matches VendorInventoryWriter — no orphaned duplicates), and is_available is
            // derived from the MERGED row state so a partial sheet can't hide a still-priced
            // row (e.g. a cost-only correction must not unset a row that still has a price).
            $vpp = VendorProductPrice::withTrashed()->firstOrNew([
                'shop_id'             => $this->shopId,
                'product_id'          => $product->id,
                'variation_option_id' => $variationOptionId,
                'period_type'         => $this->periodType,
                'effective_from'      => $this->effectiveFrom,
            ]);
            $isNew = !$vpp->exists;
            $vpp->fill($values);
            $vpp->is_available = (float) ($vpp->vendor_selling_price ?? 0) > 0 || (float) ($vpp->cost_price ?? 0) > 0;
            if ($isNew) {
                $vpp->created_by_user_id = $this->userId;
            }
            $vpp->save();
            $this->touchedProducts[$product->id] = true;

            // Optional per-product delivery charge on the same sheet.
            $deliveryCharge = $row->get('delivery_charge', null);
            if ($deliveryCharge !== null && $deliveryCharge !== '' && is_numeric($deliveryCharge)) {
                $product->update(['delivery_charge' => (float) $deliveryCharge]);
            }

            $this->rowCount++;
        }

        // Refresh the city-availability projection for every product this sheet touched.
        $availability = new AvailabilityService();
        foreach (array_keys($this->touchedProducts) as $pid) {
            $availability->recomputeForProduct((int) $pid);
        }
        if (!empty($this->touchedProducts)) {
            AvailabilityService::bustCatalogCache();
        }
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
