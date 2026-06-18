<?php

namespace Marvel\Listeners;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Variation;

// Intentionally NOT ShouldQueue: stock restore (cancel/refund) runs synchronously so a worker
// outage can never strand restored stock. The restore is a fast atomic UPDATE.
class ProductInventoryRestore
{
    /**
     * Atomically restore stock when an order/suborder is cancelled or refunded.
     * Per-suborder safe: each cancelled suborder restores only its own line items
     * (sold_quantity clamped at 0). One UPDATE — race-safe.
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
                'sold_quantity' => DB::raw("GREATEST(COALESCE(sold_quantity, 0) - {$qty}, 0)"),
            ]);

            if (!empty($eventData->pivot->variation_option_id)) {
                Variation::where('id', $eventData->pivot->variation_option_id)->update([
                    'quantity'      => DB::raw("quantity + {$qty}"),
                    'sold_quantity' => DB::raw("GREATEST(COALESCE(sold_quantity, 0) - {$qty}, 0)"),
                ]);
            }
        } catch (Exception $th) {
            Log::error('Inventory restore failed', [
                'product_id' => $eventData->id ?? null,
                'error'      => $th->getMessage(),
            ]);
        }
    }

    public function handle($event)
    {
        foreach ($event->order->products as $product) {
            $this->updateProductInventory($product);
        }
        // Refresh storefront stock immediately (mass-updates bypass model events).
        Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);
    }
}
