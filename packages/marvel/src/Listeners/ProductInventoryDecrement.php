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
use Marvel\Exceptions\InsufficientStockException;

// Intentionally NOT ShouldQueue: stock must decrement synchronously within the order request so a
// queue-worker outage can never let orders be placed without decrementing inventory (mass
// oversell). The decrement is a fast atomic conditional UPDATE (`where quantity >= qty`), and it
// runs INSIDE the order's DB::transaction (OrderController::store wraps storeOrder, which fires
// OrderProcessed synchronously) — so under the 'block' oversell policy a 0-row match throws and
// rolls the whole order back; under 'log' (legacy) it is logged and the order proceeds.
class ProductInventoryDecrement
{
    /** 'block' ⇒ a 0-row decrement aborts the order (422 + rollback); 'log' ⇒ legacy proceed. */
    protected function blocking(): bool
    {
        return config('shop.inventory_oversell_policy', 'log') === 'block';
    }
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

        // Untracked stock is UNLIMITED, so there is nothing to decrement and nothing to oversell.
        // Without this, every order against the catalogue default (track_stock = false, quantity
        // = 0) matches 0 rows on the atomic guard below and logs a spurious oversell — or, under
        // the 'block' policy, refuses a sale the admin deliberately left uncapped.
        //
        // Column-guarded because the phpunit stubs build `products` by hand; a missing column
        // reads as "tracked", which preserves the pre-catalogue behaviour exactly.
        if (\Illuminate\Support\Facades\Schema::hasColumn('products', 'track_stock')) {
            $tracked = (bool) Product::where('id', $productId)->value('track_stock');
            if (!$tracked) {
                // sold_quantity is still worth keeping — it is merchandising data, not a limit.
                Product::where('id', $productId)->update([
                    'sold_quantity' => DB::raw("COALESCE(sold_quantity, 0) + {$qty}"),
                ]);
                return;
            }
        }

        $affected = Product::where('id', $productId)
            ->where('quantity', '>=', $qty)
            ->update([
                'quantity'      => DB::raw("quantity - {$qty}"),
                'sold_quantity' => DB::raw("COALESCE(sold_quantity, 0) + {$qty}"),
            ]);

        if ($affected === 0) {
            // The atomic guard matched 0 rows: another order already took this stock.
            // Under 'block' this aborts the order (the surrounding transaction rolls
            // back every earlier decrement too — all-or-nothing). Under 'log' the
            // column still never goes negative, but we WERE about to fulfil an order
            // we can't stock — surface it so ops can cancel/refund instead of
            // silently shipping nothing.
            if ($this->blocking()) {
                throw new InsufficientStockException();
            }
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
                if ($this->blocking()) {
                    throw new InsufficientStockException();
                }
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
        } catch (InsufficientStockException $e) {
            // The oversell gate MUST escape the defensive catch — it is what
            // rolls the order back under the 'block' policy.
            throw $e;
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
        } catch (InsufficientStockException $e) {
            // Escape the defensive catch — the surrounding order transaction
            // rolls back the already-decremented components (all-or-nothing).
            throw $e;
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
