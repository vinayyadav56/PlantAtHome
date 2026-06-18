<?php

namespace Marvel\Listeners;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Variation;

// Intentionally NOT ShouldQueue: stock must decrement synchronously within the order request
// (and its transaction) so a queue-worker outage can never let orders be placed without
// decrementing inventory (mass oversell). The decrement is a fast atomic UPDATE.
class ProductInventoryDecrement
{
    /**
     * Atomically (race-safe) decrement stock so concurrent orders can't oversell.
     * One conditional UPDATE (`where quantity >= qty`) with DB-side arithmetic.
     * COALESCE guards legacy rows whose sold_quantity is NULL.
     */
    protected function updateProductInventory($eventData, $orderId = null)
    {
        try {
            $qty = (int) $eventData->pivot->order_quantity;
            if ($qty <= 0) {
                return;
            }

            $affected = Product::where('id', $eventData->id)
                ->where('quantity', '>=', $qty)
                ->update([
                    'quantity'      => DB::raw("quantity - {$qty}"),
                    'sold_quantity' => DB::raw("COALESCE(sold_quantity, 0) + {$qty}"),
                ]);

            if ($affected === 0) {
                // The atomic guard matched 0 rows: another order already took this stock.
                // The column never goes negative (good) but we WERE about to fulfil an order
                // we can't stock — surface it so ops can cancel/refund instead of silently
                // shipping nothing.
                Log::warning('Inventory oversell detected (product stock could not cover order)', [
                    'order_id'   => $orderId,
                    'product_id' => $eventData->id,
                    'qty'        => $qty,
                ]);
            }

            if (!empty($eventData->pivot->variation_option_id)) {
                $vAffected = Variation::where('id', $eventData->pivot->variation_option_id)
                    ->where('quantity', '>=', $qty)
                    ->update([
                        'quantity'      => DB::raw("quantity - {$qty}"),
                        'sold_quantity' => DB::raw("COALESCE(sold_quantity, 0) + {$qty}"),
                    ]);
                if ($vAffected === 0) {
                    Log::warning('Inventory oversell detected (variation stock could not cover order)', [
                        'order_id'            => $orderId,
                        'variation_option_id' => $eventData->pivot->variation_option_id,
                        'qty'                 => $qty,
                    ]);
                }
            }
        } catch (Exception $th) {
            // A genuine DB error here used to be swallowed silently — at least record it.
            Log::error('Inventory decrement failed', [
                'product_id' => $eventData->id ?? null,
                'error'      => $th->getMessage(),
            ]);
        }
    }

    public function handle($event)
    {
        foreach ($event->order->products as $product) {
            $this->updateProductInventory($product, $event->order->id ?? null);
        }
        // Atomic mass-updates bypass Eloquent events, so bump the products cache
        // version to refresh storefront stock immediately.
        Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);
    }
}
