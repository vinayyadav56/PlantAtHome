<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\NotifyLogs;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\VendorInventoryReview;
use Marvel\Database\Models\VendorProductPrice;
use Marvel\Services\AvailabilityService;

/**
 * Admin review queue over vendor inventory (super-admin routes).
 *
 * The pipeline's one rule: NOTHING a vendor submits prices or lists anything until an
 * admin approves it. Enforcement lives in the pricing/availability predicates; this
 * controller is the state machine's admin half — queue, detail, approve / reject /
 * request-changes (single + bulk), each with an append-only audit row and a vendor
 * notification, and an availability recompute so approval takes effect immediately.
 */
class InventoryReviewController extends Controller
{
    /** Legal admin transitions. Terminal-ish states can be re-reviewed deliberately. */
    private const ACTIONS = [
        'approve'         => VendorProductPrice::REVIEW_APPROVED,
        'reject'          => VendorProductPrice::REVIEW_REJECTED,
        'request_changes' => VendorProductPrice::REVIEW_CHANGES,
    ];

    /** GET /admin/inventory-reviews — the queue. */
    public function index(Request $request)
    {
        $limit = min(100, max(1, (int) ($request->limit ?? 25)));

        $query = VendorProductPrice::query()
            ->with(['product:id,name,slug,sku,image,type_id', 'shop:id,name,slug,owner_id'])
            ->when($request->filled('status'), fn ($q) => $q->where('review_status', $request->status))
            ->when($request->filled('shop_id'), fn ($q) => $q->where('shop_id', (int) $request->shop_id))
            ->when($request->filled('date_from'), fn ($q) => $q->where('submitted_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->where('submitted_at', '<=', $request->date_to . ' 23:59:59'))
            ->when($request->filled('category_id'), function ($q) use ($request) {
                $q->whereHas('product.categories', fn ($c) => $c->where('categories.id', (int) $request->category_id));
            })
            ->when($request->filled('city'), function ($q) use ($request) {
                $city = trim((string) $request->city);
                $q->whereIn('shop_id', DB::table('vendor_service_areas')
                    ->whereRaw('LOWER(city) = ?', [mb_strtolower($city)])
                    ->where('is_active', true)->select('shop_id'));
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = trim((string) $request->search);
                $q->where(function ($w) use ($term) {
                    if (ctype_digit($term)) {
                        $w->orWhere('id', (int) $term)->orWhere('shop_id', (int) $term);
                    }
                    $w->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%"))
                      ->orWhereHas('shop', fn ($s) => $s->where('name', 'like', "%{$term}%"));
                });
            });

        // Oldest submissions first inside the queue view — review priority is wait time.
        $page = $query->orderByRaw('COALESCE(submitted_at, created_at) asc')->paginate($limit);

        $this->decorateRows($page->getCollection());

        return response()->json([
            'data'     => $page->items(),
            'total'    => $page->total(),
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
            'counters' => $this->counters(),
        ]);
    }

    /** GET /admin/inventory-reviews/{id} — full review detail + audit history. */
    public function show(int $id)
    {
        $row = VendorProductPrice::withTrashed()
            ->with(['product.categories:id,name', 'product.type:id,name', 'shop'])
            ->findOrFail($id);

        $this->decorateRows(collect([$row]));

        optional($row->product)->setAppends([]);
        $owner = $row->shop?->owner_id
            ? DB::table('users')->where('id', $row->shop->owner_id)->first(['id', 'name', 'email'])
            : null;

        $history = VendorInventoryReview::with('actor:id,name')
            ->where('vendor_product_price_id', $row->id)
            ->orderBy('id')
            ->get();

        $cities = DB::table('vendor_service_areas')->where('shop_id', $row->shop_id)
            ->where('is_active', true)->distinct()->pluck('city');

        return response()->json([
            'inventory' => $row,
            'vendor'    => [
                'shop_id'         => $row->shop_id,
                'name'            => $row->shop?->name,
                'slug'            => $row->shop?->slug,
                'approval_status' => $row->shop?->approval_status ?? null,
                'owner'           => $owner,
                'cities'          => $cities,
            ],
            'history'   => $history,
        ]);
    }

    /** POST /admin/inventory-reviews/{id}/action — approve | reject | request_changes. */
    public function action(Request $request, int $id)
    {
        $validated = $request->validate([
            'action'  => 'required|in:approve,reject,request_changes',
            'comment' => 'required_unless:action,approve|nullable|string|max:2000',
            // Optimistic concurrency: the status the admin SAW when acting. A stale
            // action (someone else reviewed first) is refused, never silently applied.
            'expected_status' => 'nullable|string',
        ]);

        $result = $this->applyAction(
            $id,
            $validated['action'],
            $validated['comment'] ?? null,
            $request->user()?->id,
            $validated['expected_status'] ?? null,
        );
        if (!$result['ok']) {
            return response()->json(['message' => $result['error']], $result['status']);
        }
        return response()->json($result);
    }

    /** POST /admin/inventory-reviews/bulk — same rules, per-row results, no partial silence. */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'ids'     => 'required|array|min:1|max:200',
            'ids.*'   => 'integer',
            'action'  => 'required|in:approve,reject,request_changes',
            'comment' => 'required_unless:action,approve|nullable|string|max:2000',
        ]);

        $results = [];
        foreach ($validated['ids'] as $rowId) {
            $r = $this->applyAction((int) $rowId, $validated['action'], $validated['comment'] ?? null, $request->user()?->id, null);
            $results[] = ['id' => (int) $rowId, 'ok' => $r['ok'], 'error' => $r['ok'] ? null : $r['error']];
        }
        $okCount = collect($results)->where('ok', true)->count();

        return response()->json([
            'applied' => $okCount,
            'failed'  => count($results) - $okCount,
            'results' => $results,
            'counters' => $this->counters(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────

    private function applyAction(int $id, string $action, ?string $comment, ?int $adminId, ?string $expected): array
    {
        $row = VendorProductPrice::withTrashed()->find($id);
        if (!$row) {
            return ['ok' => false, 'status' => 404, 'error' => "Inventory row {$id} not found."];
        }
        if ($row->trashed()) {
            return ['ok' => false, 'status' => 422, 'error' => 'This inventory row was withdrawn by the vendor.'];
        }

        $new  = self::ACTIONS[$action];
        $prev = $row->review_status;

        if ($prev === $new) {
            return ['ok' => false, 'status' => 422, 'error' => "Already {$new}."];
        }
        if ($expected !== null && $expected !== $prev) {
            return ['ok' => false, 'status' => 409, 'error' => "Status changed to '{$prev}' while you were reviewing — reload before acting."];
        }

        // Query-builder update on purpose: the model's saving() hook handles VENDOR
        // transitions; admin decisions are recorded explicitly here. The WHERE on the
        // previous status makes the write itself concurrency-safe — an approval can be
        // superseded by a later explicit action, never silently overwritten by a race.
        $updated = VendorProductPrice::where('id', $row->id)
            ->where('review_status', $prev)
            ->update([
                'review_status'       => $new,
                'review_comment'      => $comment,
                'reviewed_by_user_id' => $adminId,
                'reviewed_at'         => now(),
                'approved_at'         => $new === VendorProductPrice::REVIEW_APPROVED ? now() : $row->approved_at,
            ]);
        if (!$updated) {
            return ['ok' => false, 'status' => 409, 'error' => 'Another review landed first — reload and re-check.'];
        }

        VendorInventoryReview::log($row, $prev, $new, $action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : 'changes_requested'), $adminId, $comment);

        // Visibility must change NOW, in both directions (approve ⇒ listed, reject a
        // previously-approved row ⇒ de-listed).
        try {
            app(AvailabilityService::class)->recomputeForProduct((int) $row->product_id);
        } catch (\Throwable $e) {
            Log::warning('inventory-review recompute failed', ['product' => $row->product_id, 'error' => $e->getMessage()]);
        }

        $this->notifyVendor($row->fresh() ?? $row, $new, $comment);

        return ['ok' => true, 'status' => 200, 'id' => $row->id, 'review_status' => $new, 'counters' => $this->counters()];
    }

    /** In-app bell + templated email (both best-effort — a review must never fail on notify). */
    private function notifyVendor(VendorProductPrice $row, string $status, ?string $comment): void
    {
        try {
            $shop = Shop::find($row->shop_id);
            $ownerId = $shop?->owner_id;
            if (!$ownerId) {
                return;
            }
            $product = $row->product()->first(['id', 'name']);
            $label = [
                VendorProductPrice::REVIEW_APPROVED => 'approved',
                VendorProductPrice::REVIEW_REJECTED => 'rejected',
                VendorProductPrice::REVIEW_CHANGES  => 'sent back with change requests',
            ][$status] ?? $status;
            $text = "Your inventory for \"{$product?->name}\" was {$label}."
                . ($comment ? " Reason: {$comment}" : '');

            NotifyLogs::create([
                'receiver'             => $ownerId,
                'sender'               => null,
                'notify_type'          => 'inventory_review',
                'notify_receiver_type' => 'vendor',
                'is_read'              => false,
                'notify_tracker'       => (string) $row->id,
                'notify_text'          => $text,
            ]);

            $eventKey = [
                VendorProductPrice::REVIEW_APPROVED => 'vendor-inventory-approved',
                VendorProductPrice::REVIEW_REJECTED => 'vendor-inventory-rejected',
                VendorProductPrice::REVIEW_CHANGES  => 'vendor-inventory-changes-requested',
            ][$status] ?? null;
            $ownerEmail = DB::table('users')->where('id', $ownerId)->value('email');
            if ($eventKey && $ownerEmail && class_exists(\Marvel\Services\EmailService::class)) {
                app(\Marvel\Services\EmailService::class)->send($eventKey, $ownerEmail, [
                    'vendor_name'  => $shop->name,
                    'product_name' => $product?->name,
                    'status'       => $label,
                    'reason'       => $comment ?? '',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('inventory-review notify failed', ['row' => $row->id, 'error' => $e->getMessage()]);
        }
    }

    private function counters(): array
    {
        $today = now()->startOfDay();
        return [
            'pending'           => VendorProductPrice::where('review_status', VendorProductPrice::REVIEW_PENDING)->count(),
            'changes_requested' => VendorProductPrice::where('review_status', VendorProductPrice::REVIEW_CHANGES)->count(),
            'approved_today'    => VendorInventoryReview::where('action', 'approved')->where('created_at', '>=', $today)->count(),
            'rejected_today'    => VendorInventoryReview::where('action', 'rejected')->where('created_at', '>=', $today)->count(),
        ];
    }

    /** Attach variant titles + drop product $appends (a query per row otherwise). */
    private function decorateRows($rows): void
    {
        $variantIds = $rows->pluck('variation_option_id')->filter()->unique()->values();
        $titles = $variantIds->isEmpty()
            ? collect()
            : DB::table('variation_options')->whereIn('id', $variantIds)->pluck('title', 'id');
        $rows->each(function ($vpp) use ($titles) {
            optional($vpp->product)->setAppends([]);
            $vpp->variant_title = $vpp->variation_option_id ? ($titles[$vpp->variation_option_id] ?? null) : null;
        });
    }
}
