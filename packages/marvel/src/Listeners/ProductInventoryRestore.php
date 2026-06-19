<?php

namespace Marvel\Listeners;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Variation;
use Marvel\Enums\ProductType;

// Intentionally NOT ShouldQueue: stock restore (cancel/refund) runs synchronously so a worker
// outage can never strand restored stock. The restore is a fast atomic UPDATE.
class ProductInventoryRestore
{
    /**
     * Atomically restore stock when an order/suborder is cancelled or refunded.
     * Per-suborder safe: each cancelled suborder restores only its own line items
     * (sold_quantity clamped at 0). One UPDATE — race-safe.
     */
    /** Atomic restore of ONE inventory unit — shared by single products + bundle components. */
    protected function restoreUnit(int $productId, ?int $variationOptionId, int $qty): void
    {
        if ($qty <= 0) {
            return;
        }

        Product::where('id', $productId)->update([
            'quantity'      => DB::raw("quantity + {$qty}"),
            'sold_quantity' => DB::raw("GREATEST(COALESCE(sold_quantity, 0) - {$qty}, 0)"),
        ]);

        if (!empty($variationOptionId)) {
            Variation::where('id', $variationOptionId)->update([
                'quantity'      => DB::raw("quantity + {$qty}"),
                'sold_quantity' => DB::raw("GREATEST(COALESCE(sold_quantity, 0) - {$qty}, 0)"),
            ]);
        }
    }

    protected function updateProductInventory($eventData)
    {
        try {
            $this->restoreUnit(
                (int) $eventData->id,
                $eventData->pivot->variation_option_id ?? null,
                (int) $eventData->pivot->order_quantity
            );
        } catch (Exception $th) {
            Log::error('Inventory restore failed', [
                'product_id' => $eventData->id ?? null,
                'error'      => $th->getMessage(),
            ]);
        }
    }

    /** Restore the COMPONENT plants of a bundle line — exact mirror of the bundle decrement. */
    protected function restoreBundle($bundle): void
    {
        try {
            $orderQty = (int) $bundle->pivot->order_quantity;
            foreach ($bundle->expandToInventoryUnits($orderQty) as $unit) {
                $this->restoreUnit((int) $unit['id'], $unit['variation_option_id'] ?? null, (int) $unit['quantity']);
            }
        } catch (Exception $th) {
            Log::error('Bundle inventory restore failed', [
                'bundle_id' => $bundle->id ?? null,
                'error'     => $th->getMessage(),
            ]);
        }
    }

    public function handle($event)
    {
        $order = $event->order;
        $hasGuard = Schema::hasColumn('orders', 'inventory_restored');

        // Split orders: a PARENT carries the UNION of its children's line items
        // (children hold per-vertical subsets). Restoring the parent's own pivot
        // would double-restore any child that was already cancelled/refunded
        // individually. So when handed a parent, restore each child (each child's
        // own guard governs), then guard the parent. A leaf/child restores itself.
        $children = $this->childrenOf($order);

        if ($children->isNotEmpty()) {
            if ($hasGuard && $order->inventory_restored) {
                return;
            }
            foreach ($children as $child) {
                $this->restoreOrderLines($child, $hasGuard);
            }
            $this->markRestored($order, $hasGuard);
        } else {
            $this->restoreOrderLines($order, $hasGuard);
        }

        // Refresh storefront stock immediately (mass-updates bypass model events).
        Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);
    }

    /** Restore one order's own line items, governed by its own idempotency guard. */
    protected function restoreOrderLines($order, bool $hasGuard): void
    {
        if ($hasGuard && $order->inventory_restored) {
            return;
        }

        foreach ($order->products as $product) {
            if ($product->product_type === ProductType::BUNDLE) {
                $this->restoreBundle($product);
            } else {
                $this->updateProductInventory($product);
            }
        }

        $this->markRestored($order, $hasGuard);
    }

    protected function markRestored($order, bool $hasGuard): void
    {
        if (!$hasGuard) {
            return;
        }
        try {
            $order->inventory_restored = true;
            $order->saveQuietly();
        } catch (Exception $th) {
            // Never let the guard write break the cancel/refund money path.
            Log::error('Inventory restore guard write failed', ['order_id' => $order->id ?? null, 'error' => $th->getMessage()]);
        }
    }

    /** A parent's children (with their own line items); empty for a leaf/child order. */
    protected function childrenOf($order)
    {
        if (!empty($order->parent_id)) {
            return collect();
        }
        try {
            return $order->children()->with('products')->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }
}
