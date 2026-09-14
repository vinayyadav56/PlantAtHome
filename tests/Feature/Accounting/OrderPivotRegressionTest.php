<?php

namespace Tests\Feature\Accounting;

use Marvel\Database\Repositories\OrderRepository;

/** Staging regression: enriched cart lines must never leak non-pivot columns into order_product. */
class OrderPivotRegressionTest extends OrdersTestCase
{
    public function test_pivot_rows_keep_only_order_product_columns(): void
    {
        $lines = [[
            'product_id' => 4041, 'variation_option_id' => 12347, 'order_quantity' => 1, 'unit_price' => 274.8, 'subtotal' => 274.8,
            // GST snapshot + accounting P3 enrichment that rides on the line for order_items
            'hsn_code' => '0602', 'tax_rate' => 0, 'taxable_value' => 274.8, 'cgst_amount' => 0, 'sgst_amount' => 0, 'igst_amount' => 0, 'tax_amount' => 0,
            'ownership_model' => 'VENDOR_SUPPLIED', 'discount_amount' => 0, 'discount_funded_by' => 'platform', 'delivery_allocation' => 22.09,
        ]];
        $rows = OrderRepository::pivotRows($lines);
        $this->assertSame(['product_id', 'variation_option_id', 'order_quantity', 'unit_price', 'subtotal'], array_keys($rows[0]));
        $this->assertSame(274.8, $rows[0]['subtotal']);
    }
}
