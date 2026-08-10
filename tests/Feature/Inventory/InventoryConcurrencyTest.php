<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Exceptions\InsufficientStockException;
use Marvel\Listeners\ProductInventoryDecrement;
use Tests\TestCase;

/**
 * Inventory oversell — the acceptance criterion: with quantity = 1 and many
 * purchase attempts, exactly ONE succeeds and the counter never goes negative.
 *
 * Like CouponRedemptionTest, PHPUnit can't run true OS-level parallelism, so
 * this proves the enforcement PRIMITIVE: the atomic conditional UPDATE
 * (`WHERE quantity >= qty`) is self-serialising on MySQL (row lock on the
 * UPDATE), and under the 'block' policy a 0-row match throws
 * InsufficientStockException through the listener's defensive catches so the
 * surrounding order transaction rolls everything back. Under 'log' (legacy
 * default) the floor still holds — the counter cannot go negative.
 */
final class InventoryConcurrencyTest extends TestCase
{
    private ProductInventoryDecrement $listener;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->integer('quantity')->default(0);
            $t->integer('sold_quantity')->nullable();
            $t->string('product_type')->default('simple');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('variation_options', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('quantity')->default(0);
            $t->integer('sold_quantity')->nullable();
            $t->timestamps();
        });

        $this->listener = new ProductInventoryDecrement();
    }

    private function seedProduct(int $qty): int
    {
        return (int) DB::table('products')->insertGetId([
            'name' => 'Areca Palm', 'quantity' => $qty, 'product_type' => 'simple',
        ]);
    }

    /** A fake OrderProcessed-shaped event: one simple-product cart line. */
    private function eventFor(int $productId, int $orderQty = 1, ?int $variationId = null, int $orderId = 1): object
    {
        $line = (object) [
            'id'           => $productId,
            'product_type' => 'simple',
            'pivot'        => (object) [
                'variation_option_id' => $variationId,
                'order_quantity'      => $orderQty,
            ],
        ];
        return (object) ['order' => (object) ['id' => $orderId, 'products' => [$line]]];
    }

    /** THE acceptance criterion: qty=1, 100 attempts, exactly one success. */
    public function test_last_unit_sells_exactly_once_under_block_policy(): void
    {
        config(['shop.inventory_oversell_policy' => 'block']);
        $pid = $this->seedProduct(1);

        $successes = 0;
        $rejections = 0;
        for ($attempt = 1; $attempt <= 100; $attempt++) {
            try {
                $this->listener->handle($this->eventFor($pid, 1, null, $attempt));
                $successes++;
            } catch (InsufficientStockException) {
                $rejections++;
            }
        }

        $this->assertSame(1, $successes, 'exactly one of 100 attempts may take the last unit');
        $this->assertSame(99, $rejections);
        $row = DB::table('products')->find($pid);
        $this->assertSame(0, (int) $row->quantity, 'stock must never go negative');
        $this->assertSame(1, (int) $row->sold_quantity);
    }

    public function test_log_policy_keeps_legacy_behavior_but_floor_holds(): void
    {
        // Default policy — the order proceeds, but the counter still can't go negative.
        $pid = $this->seedProduct(1);

        $this->listener->handle($this->eventFor($pid));
        $this->listener->handle($this->eventFor($pid)); // must NOT throw

        $row = DB::table('products')->find($pid);
        $this->assertSame(0, (int) $row->quantity);
        $this->assertSame(1, (int) $row->sold_quantity, 'the failed attempt must not count as sold');
    }

    public function test_variation_shortfall_blocks_too(): void
    {
        config(['shop.inventory_oversell_policy' => 'block']);
        $pid = $this->seedProduct(10);
        $vid = (int) DB::table('variation_options')->insertGetId(['product_id' => $pid, 'quantity' => 1]);

        $this->listener->handle($this->eventFor($pid, 1, $vid));

        $this->expectException(InsufficientStockException::class);
        try {
            $this->listener->handle($this->eventFor($pid, 1, $vid));
        } finally {
            $this->assertSame(0, (int) DB::table('variation_options')->find($vid)->quantity);
        }
    }

    public function test_block_inside_transaction_rolls_back_earlier_lines(): void
    {
        // Two cart lines: A has stock, B does not. The order transaction must
        // leave A untouched when B blocks — all-or-nothing, like the real
        // order path (OrderController::store wraps storeOrder in a transaction).
        config(['shop.inventory_oversell_policy' => 'block']);
        $a = $this->seedProduct(5);
        $b = $this->seedProduct(0);

        $event = (object) ['order' => (object) ['id' => 9, 'products' => [
            $this->eventFor($a)->order->products[0],
            $this->eventFor($b)->order->products[0],
        ]]];

        try {
            DB::transaction(fn () => $this->listener->handle($event));
            $this->fail('expected InsufficientStockException');
        } catch (InsufficientStockException) {
            // expected
        }

        $this->assertSame(5, (int) DB::table('products')->find($a)->quantity, 'line A must roll back');
        $this->assertSame(0, (int) DB::table('products')->find($b)->quantity);
    }

    public function test_oversized_order_cannot_drain_partial_stock(): void
    {
        config(['shop.inventory_oversell_policy' => 'block']);
        $pid = $this->seedProduct(3);

        try {
            $this->listener->handle($this->eventFor($pid, 5));
            $this->fail('expected InsufficientStockException');
        } catch (InsufficientStockException) {
        }

        $this->assertSame(3, (int) DB::table('products')->find($pid)->quantity, 'qty>stock must not partially deduct');
    }
}
