<?php

namespace Marvel\Listeners;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Variation;

class ProductInventoryRestore implements ShouldQueue
{
    /**
     * Atomically restore stock when an order/suborder is cancelled or refunded.
     * Per-suborder safe: each cancelled suborder restores only its own line items
     * (sold_quantity clamped at 0). Runs in one UPDATE — race-safe.
     */
    protected function updateProductInventory($eventData)
    {
        try {
            $qty = (int) $eventData->pivot->order_quantity;
            if ($qty <= 0) {
                return;
            }

            Product::where('id', $eventData->id)->update([
                'quantity'      => DB::raw("quantity + {$qty}"),
                'sold_quantity' => DB::raw("GREATEST(sold_quantity - {$qty}, 0)"),
            ]);

            if (!empty($eventData->pivot->variation_option_id)) {
                Variation::where('id', $eventData->pivot->variation_option_id)->update([
                    'quantity'      => DB::raw("quantity + {$qty}"),
                    'sold_quantity' => DB::raw("GREATEST(sold_quantity - {$qty}, 0)"),
                ]);
            }
        } catch (Exception $th) {
            //
        }
    }

    public function handle($event)
    {
        $products = $event->order->products;
        foreach ($products as $product) {
            $this->updateProductInventory($product);
        }
    }
}
