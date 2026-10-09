<?php

namespace Marvel\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The vendor price sheet as an IMPORT-READY workbook (owner, 2026-10-09): one row per product +
 * size, under the exact headings VendorPriceSheetImport reads — `sku`, `product_id`, `size`,
 * `price` — plus the product name for the person filling it in (the importer ignores it). A vendor
 * types prices into the blank `price` column and the same file uploads in Vendor Pricing → Import
 * with no retyping. Rows come from VendorPriceSheetService::importRows().
 */
class VendorPriceSheetExport implements FromArray, WithHeadings
{
    /** @param array<int, array<int, string|int>> $rows sku, product_id, product, size, price */
    public function __construct(private array $rows)
    {
    }

    public function headings(): array
    {
        return ['sku', 'product_id', 'product', 'size', 'price'];
    }

    public function array(): array
    {
        return $this->rows;
    }
}
