<?php

namespace Marvel\Listeners;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Variation;

class ProductInventoryDecrement implements ShouldQueue
{
    /**
     * Atomically (race-safe) decrement stock so concurrent orders can't oversell.
     * The conditional `where quantity >= qty` + DB-side arithmetic run in one
     * UPDATE, so two simultaneous orders can never both deduct the last unit.
     */
    protected function updateProductInventory($eventData)
    {
        try {
            $qty = (int) $eventData->pivot->order_quantity;
            if ($qty <= 0) {
                return;
            }

            $before = Product::where('id', $eventData->id)->value('quantity');
            $affected = Product::where('id', $eventData->id)
                ->where('quantity', '>=', $qty)
                ->update([
                    'quantity'      => DB::raw("quantity - {$qty}"),
                    'sold_quantity' => DB::raw("COALESCE(sold_quantity, 0) + {$qty}"),
                ]);
            $after = Product::where('id', $eventData->id)->value('quantity');
            \Illuminate\Support\Facades\Log::info('INV_DEC2', ['pid' => $eventData->id, 'qty' => $qty, 'aff' => $affected, 'before' => $before, 'after' => $after]);

            if (!empty($eventData->pivot->variation_option_id)) {
                Variation::where('id', $eventData->pivot->variation_option_id)
                    ->where('quantity', '>=', $qty)
                    ->update([
                        'quantity'      => DB::raw("quantity - {$qty}"),
                        'sold_quantity' => DB::raw("COALESCE(sold_quantity, 0) + {$qty}"),
                    ]);
            }
        } catch (Exception $th) {
            //
        }
    }

    public function handle($event)
    {
        foreach ($event->order->products as $product) {
            $this->updateProductInventory($product);
        }
        // Atomic mass-updates bypass Eloquent events, so bump the products cache
        // version to refresh storefront stock immediately (otherwise the cached
        // list/PDP would show stale quantities for up to the cache TTL).
        Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);
    }
}
