<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Enums\ProductStatus;
use Marvel\Enums\ProductType;

/**
 * Derived bundle inventory. A bundle holds NO stock of its own — its available
 * quantity is computed live from its component plants:
 *
 *   available = MIN over components of  floor( componentAvailable(c) / perBundleQty(c) )
 *
 * `componentAvailable` MUST read the SAME source the deduction listener writes
 * (gated by MARKETPLACE_RESERVE_STOCK) or read-availability and write-deduction
 * disagree and the bundle oversells. Every read here is total-failure-safe:
 * a missing / unpublished / soft-deleted / zero-stock component yields 0 for the
 * whole bundle; nothing throws.
 */
class BundleInventoryService
{
    /** Derived available bundle count. Non-bundles return their own quantity unchanged. */
    public function available(Product $bundle): int
    {
        if ($bundle->product_type !== ProductType::BUNDLE) {
            return (int) ($bundle->quantity ?? 0);
        }

        $items = $bundle->relationLoaded('bundleItems') ? $bundle->bundleItems : $bundle->bundleItems()->get();
        if ($items->isEmpty()) {
            return 0; // all components gone → not sellable
        }

        $min = null;
        foreach ($items as $child) {
            // A present-but-unpublished/trashed component makes the bundle unfulfillable.
            if (!$this->componentSellable($child)) {
                return 0;
            }
            $perBundle = max(1, (int) ($child->pivot->quantity ?: 1));
            $units = intdiv(max(0, $this->componentAvailable($child)), $perBundle);
            $min = $min === null ? $units : min($min, $units);
            if ($min === 0) {
                break;
            }
        }

        return (int) ($min ?? 0);
    }

    /**
     * Component stock = products.quantity — the SAME column the order listener
     * decrements/restores for every product type (simple, variable AND bundle
     * components). The read MUST match the write or the bundle oversells, so this
     * does NOT branch on the reserve flag (the listener never writes vendor rows).
     */
    public function componentAvailable(Product $component): int
    {
        return (int) ($component->quantity ?? 0);
    }

    /** Per-component warnings (deleted/inactive/zero_stock/shrunk) for admin preview + edge cases. Never throws. */
    public function componentWarnings(Product $bundle): array
    {
        $childIds = DB::table('product_inclusions')
            ->where('parent_id', $bundle->id)
            ->where('relation', 'bundle')
            ->pluck('child_id');

        if ($childIds->isEmpty()) {
            return [['id' => null, 'name' => null, 'flags' => ['no_components']]];
        }

        $loaded = Product::withTrashed()->whereIn('id', $childIds)->get()->keyBy('id');
        $warnings = [];

        foreach ($childIds as $cid) {
            $child = $loaded->get($cid);
            $flags = [];

            if (!$child) {
                $flags[] = 'deleted';
            } elseif (method_exists($child, 'trashed') && $child->trashed()) {
                $flags[] = 'deleted';
            } elseif ($child->status !== ProductStatus::PUBLISH) {
                $flags[] = 'inactive';
            } elseif ($this->componentAvailable($child) <= 0) {
                $flags[] = 'zero_stock';
            }

            if ($flags) {
                $warnings[] = [
                    'id'    => (int) $cid,
                    'name'  => $child->name ?? null,
                    'flags' => $flags,
                ];
            }
        }

        return $warnings;
    }

    /** A component can contribute stock only if it's a live, published plant. */
    protected function componentSellable(Product $component): bool
    {
        if (method_exists($component, 'trashed') && $component->trashed()) {
            return false;
        }

        return $component->status === ProductStatus::PUBLISH;
    }
}
