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
 *   sku | product_id   (one required — identifies the product)
 *   size                (optional — variation_option title or sku)
 *   cost_price          (₹ cost for this vendor/period; 0 ⇒ unavailable)
 *   delivery_charge     (optional — sets products.delivery_charge)
 *
 * cost_price = 0 marks the row available=false (orderable, "available in 6h").
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
        private int $batchId
    ) {
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $line = $i + 2; // +1 heading, +1 to 1-index
            $sku       = $this->str($row, 'sku');
            $productId = $this->str($row, 'product_id') ?: $this->str($row, 'id');
            $size      = $this->str($row, 'size');
            $costRaw   = $row->get('cost_price', $row->get('cost', null));

            if (!$sku && !$productId) {
                $this->fail($line, 'Missing sku / product_id.');
                continue;
            }
            if ($costRaw === null || $costRaw === '') {
                $this->fail($line, 'Missing cost_price.');
                continue;
            }
            if (!is_numeric($costRaw) || (float) $costRaw < 0) {
                $this->fail($line, "Invalid cost_price '{$costRaw}'.");
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

            $cost = (float) $costRaw;

            // Optional per-vendor stock + fulfillment mode (only set when present, so
            // a price-only sheet never wipes existing stock).
            $values = [
                'cost_price'      => $cost,
                'is_available'    => $cost > 0,
                'effective_to'    => $this->effectiveTo,
                'source'          => 'excel',
                'import_batch_id' => $this->batchId,
                'deleted_at'      => null,
            ];
            $stockRaw = $row->get('stock_qty', $row->get('stock', null));
            if (is_numeric($stockRaw)) {
                $values['stock_qty'] = max(0, (int) $stockRaw);
            }
            $modeRaw = strtolower($this->str($row, 'fulfillment_mode') ?: $this->str($row, 'fulfillment'));
            if (in_array($modeRaw, ['local', 'courier', 'both'], true)) {
                $values['fulfillment_mode'] = $modeRaw;
            }

            VendorProductPrice::updateOrCreate(
                [
                    'shop_id'             => $this->shopId,
                    'product_id'          => $product->id,
                    'variation_option_id' => $variationOptionId,
                    'period_type'         => $this->periodType,
                    'effective_from'      => $this->effectiveFrom,
                ],
                $values
            );
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
