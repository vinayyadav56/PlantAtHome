<?php

namespace Marvel\Listeners;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Variation;
use Marvel\Enums\ProductType;

// Intentionally NOT ShouldQueue: stock must decrement synchronously within the order request so a
// queue-worker outage can never let orders be placed without decrementing inventory (mass
// oversell). The decrement is a fast atomic conditional UPDATE (`where quantity >= qty`). NB the
// order-create path is NOT wrapped in a DB transaction, so this is inline-but-not-atomic with the
// order write; a 0-row match (oversell) is logged and the order still proceeds (pre-existing).
class ProductInventoryDecrement
{
    /**
     * Atomically (race-safe) decrement stock so concurrent orders can't oversell.
     * One conditional UPDATE (`where quantity >= qty`) with DB-side arithmetic.
     * COALESCE guards legacy rows whose sold_quantity is NULL.
     */
    /**
     * Atomic, race-safe decrement of ONE inventory unit (a product + optional
     * variation). The shared primitive used for both single products and each
     * expanded bundle component, so bundle deduct stays symmetric with restore.
     */
    protected function decrementUnit(int $productId, ?int $variationOptionId, int $qty, $orderId = null): void
    {
        if ($qty <= 0) {
            return;
        }

        $affected = Product::where('id', $productId)
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
                'product_id' => $productId,
                'qty'        => $qty,
            ]);
        }

        if (!empty($variationOptionId)) {
            $vAffected = Variation::where('id', $variationOptionId)
                ->where('quantity', '>=', $qty)
                ->update([
                    'quantity'      => DB::raw("quantity - {$qty}"),
                    'sold_quantity' => DB::raw("COALESCE(sold_quantity, 0) + {$qty}"),
                ]);
            if ($vAffected === 0) {
                Log::warning('Inventory oversell detected (variation stock could not cover order)', [
                    'order_id'            => $orderId,
                    'variation_option_id' => $variationOptionId,
                    'qty'                 => $qty,
                ]);
            }
        }
    }

    protected function updateProductInventory($eventData, $orderId = null)
    {
        try {
            $this->decrementUnit(
                (int) $eventData->id,
                $eventData->pivot->variation_option_id ?? null,
                (int) $eventData->pivot->order_quantity,
                $orderId
            );
        } catch (Exception $th) {
            // A genuine DB error here used to be swallowed silently — at least record it.
            Log::error('Inventory decrement failed', [
                'product_id' => $eventData->id ?? null,
                'error'      => $th->getMessage(),
            ]);
        }
    }

    /**
     * Decrement the COMPONENT plants of a bundle line (the bundle holds no stock
     * of its own). qty per component = order_quantity × inclusion.pivot.quantity,
     * via the single Product::expandToInventoryUnits helper. Tolerant like the
     * single-product path: a shortfall is logged (per unit) and we proceed —
     * never throw into the already-placed order.
     */
    protected function decrementBundle($bundle, $orderId = null): void
    {
        try {
            $orderQty = (int) $bundle->pivot->order_quantity;
            foreach ($bundle->expandToInventoryUnits($orderQty) as $unit) {
                $this->decrementUnit((int) $unit['id'], $unit['variation_option_id'] ?? null, (int) $unit['quantity'], $orderId);
            }
        } catch (Exception $th) {
            Log::error('Bundle inventory decrement failed', [
                'bundle_id' => $bundle->id ?? null,
                'order_id'  => $orderId,
                'error'     => $th->getMessage(),
            ]);
        }
    }

    public function handle($event)
    {
        foreach ($event->order->products as $product) {
            // A bundle holds no stock of its own — always expand to its components
            // (no runtime flag: deduct and restore must stay symmetric; the proper
            // kill switch is deactivating the bundle product so it can't be sold).
            if ($product->product_type === ProductType::BUNDLE) {
                $this->decrementBundle($product, $event->order->id ?? null);
            } else {
                $this->updateProductInventory($product, $event->order->id ?? null);
            }
        }
        // Atomic mass-updates bypass Eloquent events, so bump the products cache
        // version to refresh storefront stock immediately.
        Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);
    }
}
