<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Marvel\Database\Models\Order;
use Marvel\Services\OrderItemService;

/**
 * One-time backfill of order_items from the legacy order_product pivot, for existing
 * parent (customer) orders. Idempotent — writeForOrder() skips an order that already
 * has items, so it's safe to re-run. Use --limit to test on a slice first.
 */
class BackfillOrderItemsCommand extends Command
{
    protected $signature = 'marvel:backfill-order-items {--limit=0 : Only process this many orders (0 = all)}';

    protected $description = 'Populate order_items from order_product for existing parent orders';

    public function handle(): int
    {
        $svc = new OrderItemService();
        $limit = (int) $this->option('limit');
        $count = 0;

        $query = Order::whereNull('parent_id')->with('products')->orderBy('id');
        $handle = function ($orders) use ($svc, &$count, $limit) {
            foreach ($orders as $order) {
                $products = $order->products->map(fn ($p) => [
                    'product_id'          => $p->id,
                    'variation_option_id' => $p->pivot->variation_option_id ?? null,
                    'order_quantity'      => (int) ($p->pivot->order_quantity ?? 1),
                    'unit_price'          => (float) ($p->pivot->unit_price ?? 0),
                    'subtotal'            => (float) ($p->pivot->subtotal ?? 0),
                ])->all();
                if (!empty($products)) {
                    try {
                        $svc->writeForOrder($order, $products);
                    } catch (\Throwable $e) {
                        $this->warn("Order #{$order->id}: {$e->getMessage()}");
                    }
                }
                $count++;
                if ($limit > 0 && $count >= $limit) {
                    return false; // stop chunking
                }
            }
            return true;
        };

        $query->chunkById(100, $handle);
        $this->info("Backfilled order_items for {$count} parent order(s).");
        return self::SUCCESS;
    }
}
