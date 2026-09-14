<?php

namespace Tests\Feature\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderItem;

/**
 * Orders/payments harness: minimal legacy stubs (orders, order_items, products, shops,
 * balances, wallet points, order_events, payment_intents) + the REAL accounting
 * migrations on top (foundation + P3), so the snapshot columns and unique indexes are
 * the deployed ones. Factories build the S68 scenario in a few lines.
 */
abstract class OrdersTestCase extends AccountingTestCase
{
    protected function setUp(): void
    {
        parent::setUp(); // sqlite + foundation migration + settings + COA

        Schema::create('shops', function ($t) { $t->id(); $t->string('slug')->nullable(); $t->string('name')->nullable(); $t->unsignedBigInteger('owner_id')->nullable(); $t->timestamps(); });
        Schema::create('products', function ($t) { $t->id(); $t->string('name')->nullable(); $t->unsignedBigInteger('shop_id')->nullable(); $t->softDeletes(); $t->timestamps(); }); // Product soft-deletes: the eager-load filters deleted_at
        Schema::create('balances', function ($t) { $t->id(); $t->unsignedBigInteger('shop_id'); $t->double('admin_commission_rate')->nullable(); $t->double('total_earnings')->default(0); $t->double('withdrawn_amount')->default(0); $t->double('current_balance')->default(0); });
        Schema::create('orders', function ($t) {
            $t->id(); $t->unsignedBigInteger('parent_id')->nullable(); $t->string('tracking_number')->nullable(); $t->unsignedBigInteger('customer_id')->nullable(); $t->unsignedBigInteger('shop_id')->nullable();
            $t->string('order_status')->nullable(); $t->string('payment_status')->nullable(); $t->string('payment_gateway')->nullable(); $t->unsignedBigInteger('coupon_id')->nullable();
            $t->double('amount')->nullable(); $t->double('sales_tax')->nullable(); $t->double('delivery_fee')->nullable(); $t->double('discount')->nullable(); $t->double('total')->nullable(); $t->double('paid_total')->nullable();
            $t->boolean('is_inter_state')->nullable(); $t->string('place_of_supply')->nullable(); $t->string('place_of_supply_code', 8)->nullable(); $t->decimal('taxable_amount', 14, 2)->nullable(); $t->decimal('cgst_amount', 14, 2)->nullable(); $t->decimal('sgst_amount', 14, 2)->nullable(); $t->decimal('igst_amount', 14, 2)->nullable(); $t->decimal('total_tax', 14, 2)->nullable();
            $t->decimal('delivery_taxable', 14, 2)->nullable(); $t->decimal('delivery_tax_amount', 14, 2)->nullable(); $t->json('shipping_address')->nullable(); $t->softDeletes(); $t->timestamps(); // Order soft-deletes: findOrFail filters deleted_at
        });
        Schema::create('order_items', function ($t) {
            $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('variation_option_id')->nullable(); $t->integer('order_quantity')->default(1);
            $t->decimal('unit_price', 14, 2)->default(0); $t->decimal('subtotal', 14, 2)->default(0); $t->unsignedBigInteger('assigned_shop_id')->nullable(); $t->unsignedBigInteger('vendor_product_price_id')->nullable();
            $t->decimal('vendor_price_snapshot', 14, 2)->nullable(); $t->decimal('vendor_cost_snapshot', 14, 2)->nullable(); $t->integer('reserved_qty')->default(0); $t->string('item_status')->default('pending'); $t->string('assignment_status')->default('unassigned');
            $t->string('hsn_code')->nullable(); $t->decimal('tax_rate', 5, 2)->nullable(); $t->decimal('taxable_value', 14, 2)->nullable(); $t->decimal('cgst_amount', 14, 2)->nullable(); $t->decimal('sgst_amount', 14, 2)->nullable(); $t->decimal('igst_amount', 14, 2)->nullable(); $t->decimal('tax_amount', 14, 2)->nullable();
            $t->timestamps();
        });
        // Order default-eager-loads customer + products (pivot) — stub their tables.
        Schema::create('users', function ($t) { $t->id(); $t->string('name')->nullable(); $t->string('email')->nullable(); $t->timestamps(); });
        Schema::create('order_product', function ($t) { $t->id(); $t->unsignedBigInteger('order_id'); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('variation_option_id')->nullable(); $t->integer('order_quantity')->default(1); $t->double('unit_price')->default(0); $t->double('subtotal')->default(0); $t->timestamps(); });
        Schema::create('withdraws', function ($t) { $t->id(); $t->unsignedBigInteger('shop_id'); $t->double('amount'); $t->string('payment_method')->nullable(); $t->string('status')->default('pending'); $t->text('details')->nullable(); $t->text('note')->nullable(); $t->softDeletes(); $t->timestamps(); });
        Schema::create('refunds', function ($t) { $t->id(); $t->double('amount')->default(0); $t->string('status')->default('pending'); $t->string('title')->nullable(); $t->text('description')->nullable(); $t->json('images')->nullable(); $t->unsignedBigInteger('order_id')->nullable(); $t->unsignedBigInteger('customer_id')->nullable(); $t->unsignedBigInteger('shop_id')->nullable(); $t->unsignedBigInteger('refund_policy_id')->nullable(); $t->unsignedBigInteger('refund_reason_id')->nullable(); $t->timestamps(); });
        Schema::create('shipments', function ($t) { $t->id(); $t->unsignedBigInteger('order_id')->nullable(); $t->unsignedBigInteger('shop_id')->nullable(); $t->unsignedBigInteger('delivery_partner_id')->nullable(); $t->string('status')->default('pending'); $t->string('last_status')->nullable(); $t->decimal('shipping_cost', 14, 2)->nullable(); $t->decimal('shipping_revenue', 14, 2)->nullable(); $t->timestamp('delivered_at')->nullable(); $t->timestamp('last_status_at')->nullable(); $t->timestamps(); });
        Schema::create('category_product', function ($t) { $t->unsignedBigInteger('category_id'); $t->unsignedBigInteger('product_id'); });
        Schema::create('order_wallet_points', function ($t) { $t->id(); $t->unsignedBigInteger('order_id'); $t->double('amount')->default(0); $t->timestamps(); });
        Schema::create('order_events', function ($t) { $t->id(); $t->unsignedBigInteger('order_id'); $t->string('type'); $t->string('label')->nullable(); $t->string('actor_type')->nullable(); $t->unsignedBigInteger('actor_id')->nullable(); $t->json('meta')->nullable(); $t->timestamp('created_at')->nullable(); });
        Schema::create('payment_intents', function ($t) { $t->id(); $t->unsignedBigInteger('order_id')->nullable(); $t->string('tracking_number')->nullable(); $t->string('payment_gateway')->nullable(); $t->json('payment_intent_info')->nullable(); $t->softDeletes(); $t->timestamps(); });

        foreach ([
            'packages/marvel/database/migrations/2026_09_14_000100_accounting_p3_orders_payments.php',
            // the REAL dormant vendor-ledger/settlement schema, then P4 on top of it
            'packages/marvel/database/migrations/2026_06_18_100000_create_vendor_ledger_entries_table.php',
            'packages/marvel/database/migrations/2026_06_18_100010_create_settlement_runs_table.php',
            'packages/marvel/database/migrations/2026_06_18_100020_create_vendor_settlements_table.php',
            'packages/marvel/database/migrations/2026_06_22_100000_add_cost_profit_tax_to_vendor_ledger_entries.php',
            'packages/marvel/database/migrations/2026_06_26_100000_unique_order_entry_on_vendor_ledger.php',
            'packages/marvel/database/migrations/2026_09_14_000200_accounting_p4_vendor_ledger.php',
            'packages/marvel/database/migrations/2026_09_14_000300_accounting_p8_settlements_payments.php',
            'packages/marvel/database/migrations/2026_09_14_000400_accounting_p10_refunds_returns.php',
            'packages/marvel/database/migrations/2026_09_14_000500_accounting_p12_reconciliation.php',
            'packages/marvel/database/migrations/2026_09_14_000600_accounting_p5_commission_rules.php',
        ] as $file) {
            $m = require base_path($file);
            $m->up();
        }

        DB::table('shops')->insert([
            ['id' => 1, 'slug' => 'plantathome', 'name' => 'PlantAtHome (master)'],
            ['id' => 11, 'slug' => 'vendor-a', 'name' => 'Vendor A'],
            ['id' => 12, 'slug' => 'vendor-b', 'name' => 'Vendor B'],
        ]);
    }

    /** S68 order: plant 300 @0% + pot 500 @18% incl + fertilizer 200 @5% incl + delivery 60 (follow principal), intra-state, prepaid. */
    protected function s68Order(array $overrides = [], bool $assign = true): Order
    {
        $order = Order::create(array_merge([
            'tracking_number' => 'S68-1', 'customer_id' => 5, 'shop_id' => 1, 'order_status' => 'order-completed', 'payment_status' => 'payment-success', 'payment_gateway' => 'RAZORPAY',
            'amount' => 1000.0, 'sales_tax' => 0.0, 'delivery_fee' => 60.0, 'discount' => 0.0, 'total' => 1060.0, 'paid_total' => 1060.0,
            'is_inter_state' => false, 'taxable_amount' => '914.21', 'cgst_amount' => '45.48', 'sgst_amount' => '45.46', 'igst_amount' => '0.00', 'total_tax' => '90.94',
            'delivery_taxable' => '54.85', 'delivery_tax_amount' => '5.15', 'shipping_address' => ['state' => 'Haryana'],
        ], $overrides));
        $this->line($order, ['product_id' => 101, 'unit_price' => 300, 'subtotal' => 300, 'hsn_code' => '0602', 'tax_rate' => 0, 'taxable_value' => '300.00', 'cgst_amount' => '0.00', 'sgst_amount' => '0.00', 'tax_amount' => '0.00', 'assigned_shop_id' => $assign ? 11 : null, 'vendor_price_snapshot' => $assign ? '240.00' : null]);
        $this->line($order, ['product_id' => 102, 'unit_price' => 500, 'subtotal' => 500, 'hsn_code' => '3924', 'tax_rate' => 18, 'taxable_value' => '423.73', 'cgst_amount' => '38.14', 'sgst_amount' => '38.13', 'tax_amount' => '76.27', 'assigned_shop_id' => $assign ? 11 : null, 'vendor_price_snapshot' => $assign ? '400.00' : null]);
        $this->line($order, ['product_id' => 103, 'unit_price' => 200, 'subtotal' => 200, 'hsn_code' => '3101', 'tax_rate' => 5, 'taxable_value' => '190.48', 'cgst_amount' => '4.76', 'sgst_amount' => '4.76', 'tax_amount' => '9.52', 'assigned_shop_id' => $assign ? 12 : null, 'vendor_price_snapshot' => $assign ? '160.00' : null]);
        return $order->fresh();
    }

    protected function line(Order $order, array $attrs): OrderItem
    {
        return OrderItem::create(array_merge(['order_id' => $order->id, 'order_quantity' => 1, 'igst_amount' => '0.00', 'item_status' => 'delivered', 'assignment_status' => 'approved'], $attrs));
    }

    /** Lines of a journal as [account_code => ['debit' => .., 'credit' => ..]] sums. */
    protected function byAccount($entry): array
    {
        $out = [];
        foreach ($entry->lines()->with('account')->get() as $l) {
            $code = $l->account->code;
            $out[$code]['debit'] = bcadd_safe($out[$code]['debit'] ?? '0.00', (string) $l->debit);
            $out[$code]['credit'] = bcadd_safe($out[$code]['credit'] ?? '0.00', (string) $l->credit);
        }
        ksort($out);
        return $out;
    }
}

if (!function_exists('bcadd_safe')) {
    /** decimal-string add without bcmath (test helper). */
    function bcadd_safe(string $a, string $b): string
    {
        return \Marvel\Services\Accounting\MoneyBridge::toMoney($a)->add(\Marvel\Services\Accounting\MoneyBridge::toMoney($b))->toDecimal();
    }
}
