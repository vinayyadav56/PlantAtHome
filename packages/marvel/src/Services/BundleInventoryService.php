<?php

namespace Marvel\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\VendorProductPrice;
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
    /** A large sentinel for "stock not tracked" vendor rows so they never falsely cap a bundle. */
    private const UNTRACKED = 1000000;

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

    /** Flag-aware component stock — the single source of truth shared with deduction. */
    public function componentAvailable(Product $component): int
    {
        if (OrderItemService::reserveEnabled()) {
            return $this->vendorAvailable($component);
        }

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

    /** Sum of available (stock - reserved) over a plant's effective vendor rows (reserve mode). */
    protected function vendorAvailable(Product $component): int
    {
        $today = Carbon::today()->toDateString();

        $rows = VendorProductPrice::where('product_id', $component->id)
            ->where('is_available', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->get();

        if ($rows->isEmpty()) {
            // No vendor rows → legacy single-tenant column is the only signal.
            return (int) ($component->quantity ?? 0);
        }

        // A price-only sheet (stock_qty <= 0) means "stock not tracked here" → don't cap.
        if ($rows->contains(fn ($r) => (int) ($r->stock_qty ?? 0) <= 0)) {
            return self::UNTRACKED;
        }

        return (int) $rows->sum(fn ($r) => max(0, (int) $r->stock_qty - (int) $r->reserved_qty));
    }

    /**
     * Master switch for the order-path component deduction/restore (P4). Default
     * ON; set BUNDLE_COMPONENT_DEDUCTION=false (or settings
     * options.marketplace.bundle_deduction=false) as an emergency kill switch.
     */
    public static function deductionEnabled(): bool
    {
        $env = env('BUNDLE_COMPONENT_DEDUCTION');
        if ($env !== null && $env !== '') {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }

        try {
            $settings = \Marvel\Database\Models\Settings::getData();
            $flag = $settings?->options['marketplace']['bundle_deduction'] ?? true;
            return (bool) $flag;
        } catch (\Throwable $e) {
            return true;
        }
    }
}
