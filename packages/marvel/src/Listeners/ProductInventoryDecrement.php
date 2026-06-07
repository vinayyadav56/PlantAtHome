<?php

namespace Marvel\Listeners;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Variation;

class ProductInventoryDecrement implements ShouldQueue
{
    /**
     * Atomically (race-safe) decrement stock so concurrent orders can't oversell.
     * The conditional `where quantity >= qty` + DB-side arithmetic happen in one
     * UPDATE, so two simultaneous orders can never both deduct the last unit.
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
                    'sold_quantity' => DB::raw("sold_quantity + {$qty}"),
                ]);

            if (!empty($eventData->pivot->variation_option_id)) {
                Variation::where('id', $eventData->pivot->variation_option_id)
                    ->where('quantity', '>=', $qty)
                    ->update([
                        'quantity'      => DB::raw("quantity - {$qty}"),
                        'sold_quantity' => DB::raw("sold_quantity + {$qty}"),
                    ]);
            }
        } catch (Exception $th) {
            \Illuminate\Support\Facades\Log::error('INV_DECREMENT_ERR', [
                'pid' => $eventData->id ?? null,
                'msg' => $th->getMessage(),
            ]);
        }
    }

    public function handle($event)
    {
        $products = $event->order->products;
        \Illuminate\Support\Facades\Log::info('INV_DECREMENT_FIRED', [
            'order_id' => $event->order->id ?? null,
            'product_count' => is_countable($products) ? count($products) : -1,
        ]);
        foreach ($products as $product) {
            $this->updateProductInventory($product);
        }
    }
}
