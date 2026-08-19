<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

/**
 * A vendor's (shop's) cost for a product / size, for a time window. cost_price is
 * NEVER exposed to customers — it drives PricingService (margin over nearest cost)
 * and the per-order profit snapshot. is_available=false (cost 0) ⇒ "available in 6h".
 */
class VendorProductPrice extends Model
{
    use SoftDeletes;

    protected $table = 'vendor_product_prices';

    public $guarded = [];

    public const REVIEW_DRAFT    = 'draft';
    public const REVIEW_PENDING  = 'pending_review';
    public const REVIEW_APPROVED = 'approved';
    public const REVIEW_REJECTED = 'rejected';
    public const REVIEW_CHANGES  = 'changes_requested';
    /** Admin pulled a previously-approved offer off the storefront (re-approve to restore). */
    public const REVIEW_SUSPENDED = 'suspended';

    public const REVIEW_STATUSES = [
        self::REVIEW_DRAFT,
        self::REVIEW_PENDING,
        self::REVIEW_APPROVED,
        self::REVIEW_REJECTED,
        self::REVIEW_CHANGES,
        self::REVIEW_SUSPENDED,
    ];

    /**
     * A change to any of these on an APPROVED row invalidates the approval: the admin
     * signed off on THIS offer, and a new price / delivery mode / variant identity is a
     * different offer. Deliberately excludes stock_qty / track_stock / is_available —
     * vendors adjust those daily and de-listing on every stock update would make the
     * pipeline unusable (owner decision).
     */
    public const MATERIAL_FIELDS = ['vendor_selling_price', 'fulfillment_mode', 'variation_option_id'];

    /**
     * Request-scoped actor flag. Admin-owned write paths (price-sheet import, admin
     * manual rows, super-admins editing on a vendor's behalf) set this true so their
     * writes are auto-approved; everything else is a vendor submission and enters the
     * review queue. The review controller's own status writes go through the query
     * builder, which skips model events entirely.
     */
    public static bool $adminActor = false;

    /**
     * Pending audit transitions keyed by spl_object_id. NEVER stored on the model —
     * Eloquent's magic __set would turn a loose property into an attribute and ship
     * it in the INSERT as a nonexistent column.
     */
    private static array $pendingTransitions = [];

    /**
     * Cached column presence. PUBLIC and resettable on purpose: phpunit runs every
     * stub-schema test file in one process, and each file re-creates the table with or
     * without the review columns — a stale cache would either skip the hook or write a
     * nonexistent column. Test setUp() calls resetReviewStatics().
     */
    public static ?bool $hasReviewColumn = null;

    /** Reset request/process-scoped review state (tests + long-running workers). */
    public static function resetReviewStatics(): void
    {
        static::$hasReviewColumn = null;
        static::$adminActor = false;
        static::$pendingTransitions = [];
    }

    /** Set the actor flag from the acting user (super-admins write as admin). */
    public static function actAsAdminIf($user): void
    {
        try {
            static::$adminActor = (bool) ($user && $user->hasPermissionTo('super_admin'));
        } catch (\Throwable) {
            static::$adminActor = false;
        }
    }

    /** Customer-facing predicate: only admin-approved rows may price or list anything. */
    public function scopeApproved($query)
    {
        return $query->where('vendor_product_prices.review_status', self::REVIEW_APPROVED);
    }

    protected $casts = [
        'cost_price'           => 'float',
        'vendor_selling_price' => 'float',
        'is_available'         => 'boolean',
        'effective_from'       => 'date',
        'effective_to'         => 'date',
        'stock_qty'            => 'integer',
        'reserved_qty'         => 'integer',
        'track_stock'          => 'boolean',
        'moq'                  => 'integer',
        'lead_time_days'       => 'integer',
    ];

    protected static function booted(): void
    {
        // Maintain dedupe_key so a UNIQUE index can block duplicate vendor listings for the same
        // (shop, product, variant, period, effective_from) — a plain unique index can't, because
        // MySQL treats each NULL variant/effective_from as distinct. A soft-deleted row drops out
        // of the unique space (key = null) so the same logical mapping can be re-created later.
        static::saving(function (VendorProductPrice $row) {
            if ($row->deleted_at !== null) {
                $row->dedupe_key = null;
                return;
            }
            $from = $row->effective_from
                ? \Illuminate\Support\Carbon::parse($row->effective_from)->format('Y-m-d')
                : '0';
            $row->dedupe_key = implode('|', [
                (int) $row->shop_id,
                (int) $row->product_id,
                $row->variation_option_id !== null ? (int) $row->variation_option_id : '0',
                (string) ($row->period_type ?? ''),
                $from,
            ]);
        });

        // ── Review-state machine ─────────────────────────────────────────────────────
        // ONE transition point for every write path (VendorInventoryWriter, the vendor
        // updateInventory fill-and-save, and the Excel import's direct upsert) — a hook
        // that lived only in the writer missed two of the three and left bypasses.
        static::saving(function (VendorProductPrice $row) {
            if (static::$hasReviewColumn === null) {
                static::$hasReviewColumn = Schema::hasColumn($row->getTable(), 'review_status');
            }
            if (!static::$hasReviewColumn) {
                return; // stub schemas in old tests
            }

            $vendorActor = !static::$adminActor
                && ($row->source === 'vendor' || $row->created_by_user_id || $row->updated_by_user_id);

            if (!$row->exists) {
                if ($row->review_status === null || !in_array($row->review_status, self::REVIEW_STATUSES, true)) {
                    $row->review_status = $vendorActor ? self::REVIEW_PENDING : self::REVIEW_APPROVED;
                }
                if ($row->review_status === self::REVIEW_PENDING) {
                    $row->submitted_at = $row->submitted_at ?? now();
                    self::$pendingTransitions[spl_object_id($row)] = [null, self::REVIEW_PENDING, 'submitted'];
                } elseif ($row->review_status === self::REVIEW_APPROVED) {
                    $row->approved_at = $row->approved_at ?? now();
                    self::$pendingTransitions[spl_object_id($row)] = [null, self::REVIEW_APPROVED, 'approved'];
                }
                return;
            }

            if (!$vendorActor) {
                return; // admin/system edits never demote their own approvals here
            }

            $prev = $row->getOriginal('review_status') ?? self::REVIEW_APPROVED;

            // Restoring a soft-deleted row is a material change: delete + re-add must not
            // resurrect a stale approval.
            $restored = $row->isDirty('deleted_at') && $row->deleted_at === null
                && $row->getOriginal('deleted_at') !== null;

            if ($prev === self::REVIEW_APPROVED && ($restored || $row->isDirty(self::MATERIAL_FIELDS))) {
                $row->review_status = self::REVIEW_PENDING;
                $row->submitted_at  = now();
                self::$pendingTransitions[spl_object_id($row)] = [$prev, self::REVIEW_PENDING, 'material_change'];
                return;
            }

            // Any vendor edit to a rejected / changes-requested row is the resubmission.
            if (in_array($prev, [self::REVIEW_REJECTED, self::REVIEW_CHANGES], true)
                && $row->isDirty() && !$row->isDirty('review_status')) {
                $row->review_status = self::REVIEW_PENDING;
                $row->submitted_at  = now();
                self::$pendingTransitions[spl_object_id($row)] = [$prev, self::REVIEW_PENDING, 'resubmitted'];
            }
        });

        // Audit AFTER the row exists (new rows have no id at saving-time).
        static::saved(function (VendorProductPrice $row) {
            $key = spl_object_id($row);
            if (!isset(self::$pendingTransitions[$key])) {
                return;
            }
            [$prev, $new, $action] = self::$pendingTransitions[$key];
            unset(self::$pendingTransitions[$key]);
            try {
                VendorInventoryReview::log(
                    $row,
                    $prev,
                    $new,
                    $action,
                    $row->updated_by_user_id ?? $row->created_by_user_id,
                );
            } catch (\Throwable $e) {
                // The audit table may not exist in stub-schema tests; a missing audit row
                // must never abort the inventory write itself.
                \Illuminate\Support\Facades\Log::warning('vendor-inventory review audit failed', [
                    'row' => $row->id, 'action' => $action, 'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /** True when this row carries a price the customer can be charged (vendor-set OR cost). */
    public function getHasPriceAttribute(): bool
    {
        return (float) ($this->vendor_selling_price ?? 0) > 0 || (float) ($this->cost_price ?? 0) > 0;
    }

    /** Stock a vendor can still commit (stock minus what's already reserved). */
    public function getAvailableQtyAttribute(): int
    {
        return max(0, (int) ($this->stock_qty ?? 0) - (int) ($this->reserved_qty ?? 0));
    }

    /**
     * Atomically reserve `qty` against this vendor row IF enough is available — a
     * single conditional UPDATE so concurrent orders can't oversell. Returns true on
     * success. Pair with releaseStock() on cancel and commitStock() on fulfilment.
     */
    public static function reserveStock(int $id, int $qty): bool
    {
        $qty = max(0, $qty);
        if ($qty === 0) {
            return true;
        }
        return static::where('id', $id)
            ->whereRaw('(stock_qty - reserved_qty) >= ?', [$qty])
            ->update(['reserved_qty' => \Illuminate\Support\Facades\DB::raw("reserved_qty + {$qty}")]) > 0;
    }

    /** Release a prior reservation (order cancelled). Returns true if a row was updated. */
    public static function releaseStock(int $id, int $qty): bool
    {
        $qty = max(0, $qty);
        if ($qty === 0) {
            return true;
        }
        return static::where('id', $id)
            ->where('reserved_qty', '>=', $qty)
            ->update(['reserved_qty' => \Illuminate\Support\Facades\DB::raw("reserved_qty - {$qty}")]) > 0;
    }

    /**
     * Commit a reservation on fulfilment: decrement both stock and reservation. Returns
     * true only if the guarded UPDATE actually applied — the caller must keep its handle
     * (order_items.reserved_qty) when this is false, so the held stock isn't orphaned.
     */
    public static function commitStock(int $id, int $qty): bool
    {
        $qty = max(0, $qty);
        if ($qty === 0) {
            return true;
        }
        return static::where('id', $id)
            ->where('reserved_qty', '>=', $qty)
            ->where('stock_qty', '>=', $qty)
            ->update([
                'reserved_qty' => \Illuminate\Support\Facades\DB::raw("reserved_qty - {$qty}"),
                'stock_qty'    => \Illuminate\Support\Facades\DB::raw("stock_qty - {$qty}"),
            ]) > 0;
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PriceImportBatch::class, 'import_batch_id');
    }
}
