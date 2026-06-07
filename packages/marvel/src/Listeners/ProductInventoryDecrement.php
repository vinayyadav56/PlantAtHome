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
     * One conditional UPDATE (`where quantity >= qty`) with DB-side arithmetic.
     * COALESCE guards legacy rows whose sold_quantity is NULL.
     */
    protected function updateProductInventory($eventData)
    {
        try {
            $qty = (int) $eventData->pivot->order_quantity;
            if ($qty <= 0) {
                return;
            }

            Product::where('id', $eventData->id)
                ->where('quantity', '>=', $qty)
                ->update([
                    'quantity'      => DB::raw("quantity - {$qty}"),
                    'sold_quantity' => DB::raw("COALESCE(sold_quantity, 0) + {$qty}"),
                ]);

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
        // version to refresh storefront stock immediately.
        Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);
    }
}
