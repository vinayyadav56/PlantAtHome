<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Marvel\Database\Models\PriceImportBatch;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\VendorProductPrice;
use Marvel\Database\Models\VendorServiceArea;
use Marvel\Enums\Permission;
use Marvel\Enums\ProductStatus;
use Marvel\Imports\VendorPriceSheetImport;
use Marvel\Services\AvailabilityService;
use Marvel\Services\VendorInventoryWriter;

/**
 * Vendor self-serve inventory. A vendor (store owner) searches the MASTER catalog and
 * attaches a selling price + stock to existing master products — they never create
 * products, never edit catalog content, and every write is forced to their OWN shop
 * (resolved from the authenticated user, never trusted from input). Customers never
 * see the vendor; the lowest attached price in a city is what's displayed.
 */
class VendorInventoryController extends CoreController
{
    /** The caller's shop id — an owned `shop_id` if passed, else their (single) shop. */
    private function resolveShopId(Request $request): int
    {
        $user = $request->user();
        $shops = $user ? $user->shops : collect();
        $isAdmin = $user && $user->hasPermissionTo(Permission::SUPER_ADMIN);

        if ($request->filled('shop_id')) {
            $requested = (int) $request->input('shop_id');
            // A vendor may only act on a shop they own; a super-admin may act on any shop
            // (managing a vendor's catalogue on their behalf).
            if (!$isAdmin && !$shops->contains('id', $requested)) {
                abort(403, 'You do not own this vendor shop.');
            }
            // Review-pipeline actor context, decided AFTER the shop is known: a super-admin
            // writing to the platform's OWN master shop writes as admin (their rows stay
            // approved) — that write genuinely is the admin curating the catalogue. Writing to
            // any OTHER (real vendor) shop always enters the review queue, even when an admin's
            // hands are on the keyboard, because the row is that vendor's supply claim, not the
            // admin's. Deciding this by permission alone (ignoring which shop) let an admin
            // auto-approve a brand-new vendor's entire initial catalogue.
            VendorProductPrice::actAsAdminIf($user, $requested);
            return $requested;
        }
        $first = $shops->first();
        if (!$first) {
            abort(422, 'No vendor shop is associated with your account.');
        }
        // A multi-shop owner must say WHICH shop — otherwise a caller (or a future UI path) that
        // omits shop_id would silently read/write the wrong shop's inventory.
        if ($shops->count() > 1) {
            abort(422, 'Your account manages more than one shop — please specify shop_id.');
        }
        VendorProductPrice::actAsAdminIf($user, (int) $first->id);
        return (int) $first->id;
    }

    /** GET /vendor/catalog-search — master products to attach (+ what I already supply). */
    public function catalogSearch(Request $request)
    {
        $shopId = $this->resolveShopId($request);
        $limit = min(50, max(1, (int) ($request->limit ?? 20)));

        // Published master-catalog products + this vendor's OWN pending proposals
        // (flagged pending_approval below), so a proposer can attach rate/stock
        // right away — the listing turns customer-visible only after admin approval.
        $hasProposalCol = \Illuminate\Support\Facades\Schema::hasColumn('products', 'proposed_by_shop_id');
        // The status=publish OR (own pending proposals) condition defeats the
        // (status, name) index → a filesort over the whole ~1,600-product catalogue
        // (~2s/page). Only widen to that OR when this vendor ACTUALLY has pending
        // proposals; otherwise query published-only, which the index serves ordered
        // by name with no filesort.
        $hasOwnProposals = $hasProposalCol && Product::query()
            ->whereIn('status', [ProductStatus::UNDER_REVIEW, ProductStatus::DRAFT])
            ->where('proposed_by_shop_id', $shopId)
            ->exists();
        $query = Product::query()
            ->with(['variation_options:id,product_id,title,sku,price']);
        // Master Catalog gate: a vendor may only stock what an admin has curated in. Applied to
        // the PUBLISHED branch only — a vendor's own pending proposal is not in the catalogue yet
        // by definition, and they must still be able to attach a rate to it while it waits, which
        // is the whole reason that branch exists. Approval alone still does not make it listable:
        // an admin has to move it in, and the storefront gate is what enforces that.
        //
        // Gated on membership, NOT on listing_enabled: stocking a curated product before its
        // switch is flipped is how supply gets built ahead of go-live. Bundles gate on both,
        // because a bundle containing something that cannot be sold is broken on the day.
        $available = fn ($q) => $q->where('status', ProductStatus::PUBLISH)
            ->where('is_available_product', true);
        if ($hasOwnProposals) {
            $query->where(function ($w) use ($shopId, $available) {
                $w->where(fn ($pub) => $available($pub))
                    ->orWhere(function ($own) use ($shopId) {
                        $own->whereIn('status', [ProductStatus::UNDER_REVIEW, ProductStatus::DRAFT])
                            ->where('proposed_by_shop_id', $shopId);
                    });
            });
        } else {
            $available($query);
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            // Scalable search: MySQL FULLTEXT (index-backed) for terms ≥ 3 chars;
            // LIKE fallback for short terms / non-MySQL so behaviour never regresses.
            $useFulltext = strlen($term) >= 3
                && \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'mysql';
            if ($useFulltext) {
                // Boolean-mode prefix match ("term*") so partial words still hit the index.
                $boolean = preg_replace('/[+\-><\(\)~*"@]+/', ' ', $term);
                $boolean = trim($boolean);
                $query->whereRaw(
                    'MATCH(products.name, products.sku) AGAINST (? IN BOOLEAN MODE)',
                    [$boolean === '' ? $term : $boolean . '*']
                );
            } else {
                $query->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%"));
            }
        }
        if ($request->filled('type_id')) {
            $query->where('type_id', (int) $request->type_id);
        }
        if ($request->filled('category_id')) {
            $cid = (int) $request->category_id;
            $query->whereHas('categories', fn ($c) => $c->where('categories.id', $cid));
        }

        // ── Vendor-specific availability ─────────────────────────────────────────────────
        // The catalogue this vendor sees is: master variants − variants they already sell.
        // A product with NOTHING remaining is excluded from the response entirely (a simple
        // product they already sell counts as fully attached). Server-side by design: the
        // frontend filtering this used to rely on reset on every refresh and could re-offer
        // variants the vendor already had.
        $attached = VendorProductPrice::where('shop_id', $shopId)
            ->get(['product_id', 'variation_option_id'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->pluck('variation_option_id')->all());

        if ($attached->isNotEmpty()) {
            $attachedProductIds = $attached->keys()->all();
            $variantCounts = DB::table('variation_options')
                ->whereIn('product_id', $attachedProductIds)
                ->selectRaw('product_id, count(*) c')->groupBy('product_id')->pluck('c', 'product_id');
            $fullyAttached = collect($attachedProductIds)->filter(function ($pid) use ($attached, $variantCounts) {
                $variantTotal = (int) ($variantCounts[$pid] ?? 0);
                $attachedIds = collect($attached[$pid]);
                if ($variantTotal === 0) {
                    // Simple product: owning the null-variant row means owning the product.
                    return $attachedIds->contains(null) || $attachedIds->isNotEmpty();
                }
                return $attachedIds->filter(fn ($v) => $v !== null)->unique()->count() >= $variantTotal;
            })->values()->all();
            if (!empty($fullyAttached)) {
                $query->whereNotIn('id', $fullyAttached);
            }
        }

        $select = ['id', 'name', 'slug', 'sku', 'image', 'type_id', 'product_type', 'price', 'status'];
        $page = $query->select($select)->orderBy('name')->paginate($limit);

        // Annotate already-attached + my price/stock per product for this vendor.
        $ids = collect($page->items())->pluck('id')->all();
        $mine = VendorProductPrice::where('shop_id', $shopId)->whereIn('product_id', $ids)
            ->get()->groupBy('product_id');
        // Each row serializes as a PLAIN ARRAY built here, for two reasons. (1) Product uses the
        // kodeine Metable trait, whose setAttribute() reroutes any non-column assignment
        // ($p->already_attached = …) into the products_meta relation — the value reads back fine
        // in PHP but silently VANISHES from the JSON, which is exactly how the vendor annotations
        // shipped broken. (2) It drops the model's default $appends (ratings, my_review,
        // blocked_dates, …) — each is an accessor that runs a query PER ROW during serialization
        // (~76ms/row → ~2s for a page of 20) — and none are used by the vendor catalogue.
        $page->getCollection()->transform(function ($p) use ($mine) {
            $rows = $mine[$p->id] ?? collect();
            $variants = $p->relationLoaded('variation_options') ? $p->variation_options : collect();
            // The variants this vendor can still add: master variants minus theirs. The UI
            // renders ONLY these, so an already-sold size is never selectable again.
            $attachedVariantIds = $rows->pluck('variation_option_id')->filter()
                ->map(fn ($v) => (int) $v)->all();
            $variantArr = fn ($v) => [
                'id'         => (int) $v->id,
                'product_id' => (int) $v->product_id,
                'title'      => $v->title,
                'sku'        => $v->sku,
                'price'      => $v->price,
            ];
            return [
                'id'                 => (int) $p->id,
                'name'               => $p->name,
                'slug'               => $p->slug,
                'sku'                => $p->sku,
                'image'              => $p->image,
                'type_id'            => $p->type_id,
                'product_type'       => $p->product_type,
                'price'              => $p->price,
                'status'             => $p->status,
                'already_attached'   => $rows->isNotEmpty(),
                'pending_approval'   => $p->status !== ProductStatus::PUBLISH,
                'variation_options'  => $variants->map($variantArr)->values(),
                'available_variants' => $variants
                    ->reject(fn ($v) => in_array((int) $v->id, $attachedVariantIds, true))
                    ->map($variantArr)->values(),
                'my_inventory'       => $rows->map(fn ($r) => [
                    'id'                   => $r->id,
                    'variation_option_id'  => $r->variation_option_id,
                    'vendor_selling_price' => $r->vendor_selling_price !== null ? (float) $r->vendor_selling_price : null,
                    'stock_qty'            => (int) ($r->stock_qty ?? 0),
                    'fulfillment_mode'     => $r->fulfillment_mode,
                ])->values(),
            ];
        });
        return $page;
    }

    /** POST /vendor/inventory/bulk-attach — multi-select save (price + stock per item). */
    public function bulkAttach(Request $request)
    {
        // NOTE: cost_price is deliberately NOT accepted here — it is the vendor's hidden buy price
        // that drives margin-over-cost pricing and the profit ledger, and is admin-only (set via
        // the price-sheet import). Letting a vendor write it would let them skew platform margins.
        $request->validate([
            'items'                          => 'required|array|min:1|max:500',
            'items.*.product_id'             => 'required_without:items.*.sku',
            'items.*.vendor_selling_price'   => 'nullable|numeric|min:0',
            'items.*.stock_qty'              => 'nullable|integer|min:0',
            'items.*.track_stock'            => 'nullable|boolean',
            'items.*.fulfillment_mode'       => 'nullable|in:local,courier,both',
            // Per-vendor, per-size logistics (vendor_sku maps to vpp.sku; `sku` is the
            // master-product lookup key, kept separate).
            'items.*.vendor_sku'             => 'nullable|string|max:191',
            'items.*.barcode'                => 'nullable|string|max:191',
            'items.*.weight'                 => 'nullable|numeric|min:0',
            'items.*.length'                 => 'nullable|numeric|min:0',
            'items.*.breadth'                => 'nullable|numeric|min:0',
            'items.*.height'                 => 'nullable|numeric|min:0',
        ]);
        $shopId = $this->resolveShopId($request);
        $result = (new VendorInventoryWriter())->writeItems($shopId, $request->input('items'), [
            'user_id'       => optional($request->user())->id,
            // Attach-only: adding from the catalogue must never overwrite an identity the vendor
            // already sells — a duplicate submit is counted as skipped, and the live price stays.
            // Editing an existing listing happens on My Inventory, which keeps upsert semantics.
            'skip_existing' => true,
        ]);
        // One in-app + email notification per submission batch (not per row — a 20-size
        // attach must not fire 20 bells). Vendor-actor rows enter the queue as pending.
        if (($result['saved'] ?? 0) > 0 && !\Marvel\Database\Models\VendorProductPrice::$adminActor) {
            $this->notifySubmitted($request, $shopId, (int) $result['saved']);
        }
        $result['review'] = \Marvel\Database\Models\VendorProductPrice::$adminActor ? 'approved' : 'pending_review';
        return response()->json($result);
    }

    /** Best-effort "submitted for review" notification — never fails the write. */
    private function notifySubmitted(Request $request, int $shopId, int $count): void
    {
        try {
            $shop = \Marvel\Database\Models\Shop::find($shopId);
            if (!$shop?->owner_id) {
                return;
            }
            \Marvel\Database\Models\NotifyLogs::create([
                'receiver'             => $shop->owner_id,
                'sender'               => optional($request->user())->id,
                'notify_type'          => 'inventory_review',
                'notify_receiver_type' => 'vendor',
                'is_read'              => false,
                'notify_tracker'       => 'submitted',
                'notify_text'          => "{$count} inventory item(s) submitted for review. They stay hidden from customers until an admin approves them.",
            ]);
            $email = \Illuminate\Support\Facades\DB::table('users')->where('id', $shop->owner_id)->value('email');
            if ($email && class_exists(\Marvel\Services\EmailService::class)) {
                app(\Marvel\Services\EmailService::class)->send('vendor-inventory-submitted', $email, [
                    'vendor_name'  => $shop->name,
                    'product_name' => "{$count} item(s)",
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('inventory submit notify failed', ['shop' => $shopId, 'error' => $e->getMessage()]);
        }
    }

    /** GET /vendor/inventory — the caller's attached rows. */
    public function inventory(Request $request)
    {
        $shopId = $this->resolveShopId($request);
        $limit = min(100, max(1, (int) ($request->limit ?? 30)));
        // Ordered by product then variant so a plant's sizes are adjacent — id DESC
        // scattered them and the client could only build partial groups.
        $query = VendorProductPrice::with(['product:id,name,slug,sku,image'])
            ->where('shop_id', $shopId)->orderBy('product_id')->orderBy('variation_option_id');
        if ($request->filled('search')) {
            $term = trim((string) $request->search);
            $query->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%"));
        }
        $page = $query->paginate($limit);
        // Variant titles — the row only carries variation_option_id, so without them a
        // 3-size plant renders three identical-looking rows.
        $variantIds = $page->getCollection()->pluck('variation_option_id')->filter()->unique()->values();
        $titles = $variantIds->isEmpty()
            ? collect()
            : \Illuminate\Support\Facades\DB::table('variation_options')
                ->whereIn('id', $variantIds)->pluck('title', 'id');
        // Drop the eager-loaded product's default $appends (ratings/reviews/
        // availability accessors) — a query per row during serialization; not needed here.
        $page->getCollection()->each(function ($vpp) use ($titles) {
            optional($vpp->product)->setAppends([]);
            $vpp->variant_title = $vpp->variation_option_id
                ? ($titles[$vpp->variation_option_id] ?? null)
                : null;
        });

        return $page;
    }

    /**
     * GET /vendor/low-stock — the caller's TRACKED rows at/under the low-stock threshold
     * (D2 vendor dashboard alert). stock_qty <= 0 means "untracked" and is excluded.
     */
    public function lowStock(Request $request)
    {
        $shopId = $this->resolveShopId($request);
        $threshold = max(0, (int) ($request->threshold ?? 5));
        $limit = min(100, max(1, (int) ($request->limit ?? 50)));
        return VendorProductPrice::with(['product:id,name,slug,sku,image'])
            ->where('shop_id', $shopId)
            ->where('stock_qty', '>', 0)
            ->whereRaw('(stock_qty - reserved_qty) <= ?', [$threshold])
            ->orderByRaw('(stock_qty - reserved_qty) asc')
            ->paginate($limit);
    }

    /** PATCH /vendor/inventory/{id} — edit price / stock / mode on the caller's row. */
    public function updateInventory(Request $request, $id)
    {
        $shopId = $this->resolveShopId($request);
        $row = VendorProductPrice::where('shop_id', $shopId)->findOrFail($id);
        // cost_price is admin-only (see bulkAttach) — a vendor cannot edit it here.
        $request->validate([
            'vendor_selling_price' => 'nullable|numeric|min:0',
            'stock_qty'            => 'nullable|integer|min:0',
            'track_stock'          => 'nullable|boolean',
            'fulfillment_mode'     => 'nullable|in:local,courier,both',
            'vendor_sku'           => 'nullable|string|max:191',
            'barcode'              => 'nullable|string|max:191',
            'weight'               => 'nullable|numeric|min:0',
            'length'               => 'nullable|numeric|min:0',
            'breadth'              => 'nullable|numeric|min:0',
            'height'               => 'nullable|numeric|min:0',
        ]);

        // Per-vendor, per-size logistics (guarded until the migration runs).
        if (\Illuminate\Support\Facades\Schema::hasColumn('vendor_product_prices', 'weight')) {
            if ($request->has('vendor_sku')) {
                $row->sku = $request->vendor_sku !== null ? trim((string) $request->vendor_sku) : null;
            }
            foreach (['barcode', 'weight', 'length', 'breadth', 'height'] as $f) {
                if ($request->has($f)) {
                    $row->{$f} = $request->{$f};
                }
            }
        }

        if ($request->has('vendor_selling_price')) {
            $row->vendor_selling_price = $request->vendor_selling_price !== null ? (float) $request->vendor_selling_price : null;
        }
        if ($request->has('stock_qty')) {
            $row->stock_qty = max(0, (int) $request->stock_qty);
            // Setting an explicit stock number means the vendor wants it tracked (so 0 = out of
            // stock), unless they also explicitly say otherwise below.
            if (!$request->has('track_stock')) {
                $row->track_stock = true;
            }
        }
        if ($request->has('track_stock')) {
            $row->track_stock = (bool) $request->track_stock;
        }
        if ($request->has('fulfillment_mode')) {
            $row->fulfillment_mode = $request->fulfillment_mode;
        }
        $row->is_available = ((float) ($row->vendor_selling_price ?? 0) > 0) || ((float) ($row->cost_price ?? 0) > 0);
        $row->updated_by_user_id = optional($request->user())->id;
        $row->save();

        (new AvailabilityService())->recomputeForProduct((int) $row->product_id);
        AvailabilityService::bustCatalogCache();
        return $row->fresh();
    }

    /** DELETE /vendor/inventory/{id} — stop supplying this product (soft delete). */
    public function deleteInventory(Request $request, $id)
    {
        $shopId = $this->resolveShopId($request);
        $row = VendorProductPrice::where('shop_id', $shopId)->findOrFail($id);
        $productId = (int) $row->product_id;
        $row->delete();
        (new AvailabilityService())->recomputeForProduct($productId);
        AvailabilityService::bustCatalogCache();
        return ['success' => true];
    }

    /** POST /vendor/inventory/bulk-upload — vendor Excel (price + inventory). */
    public function bulkUpload(Request $request)
    {
        // Validate before touching the file: a spreadsheet only, capped size. The file
        // is stored on the PRIVATE disk (audit/re-parse only) — never publicly served —
        // so a vendor can't drop an HTML/SVG/PHP payload at a guessable public URL.
        // NOTE: a plain CSV is frequently content-sniffed as text/plain (guessed ext "txt"),
        // so `mimes:csv` alone wrongly rejects genuine .csv uploads — accept "txt" too. The
        // real format gate is the strict client-extension check below + content parsing by
        // the importer, so this stays spreadsheet-only.
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt|max:5120']);
        $shopId = $this->resolveShopId($request);
        $uploaded = $request->file('file');
        $periodType = 'monthly';

        $ext = strtolower($uploaded->getClientOriginalExtension() ?: 'xlsx');
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            $ext = 'xlsx';
        }
        $path = $uploaded->storeAs('price-sheets', 'vendor-' . $shopId . '-self-' . time() . '.' . $ext, 'local');

        $batch = PriceImportBatch::create([
            'uploaded_by' => optional($request->user())->id,
            'shop_id'     => $shopId,
            'period_type' => $periodType,
            'file'        => $path,
            // Marked processing until the import finishes; set to completed/failed below. A worker
            // killed mid-import (OOM) then leaves an honest "processing", not a false "completed".
            'status'      => 'processing',
        ]);

        try {
            // Vendor self-serve upload — allowCost=false so a vendor cannot set the hidden cost.
            $import = new VendorPriceSheetImport($shopId, $periodType, null, null, $batch->id, optional($request->user())->id, false);
            Excel::import($import, $uploaded);
        } catch (\Throwable $e) {
            $batch->update(['status' => 'failed', 'errors' => [['line' => 0, 'error' => $e->getMessage()]]]);
            return response()->json(['message' => 'Could not read the sheet.', 'error' => $e->getMessage(), 'batch_id' => $batch->id], 422);
        }

        $batch->update([
            'row_count'   => $import->rowCount,
            'error_count' => $import->errorCount,
            'errors'      => $import->errors,
            'status'      => $import->errorCount && !$import->rowCount ? 'failed' : 'completed',
        ]);

        return response()->json([
            'message'          => "Imported {$import->rowCount} rows" . ($import->errorCount ? ", {$import->errorCount} skipped." : '.'),
            'imported'         => $import->rowCount,
            'skipped'          => $import->errorCount,
            'errors'           => $import->errors,
            'batch_id'         => $batch->id,
            'error_report_url' => $import->errorCount ? url("/api/vendor/inventory/bulk-upload/{$batch->id}/errors.csv") : null,
        ]);
    }

    /** GET /vendor/inventory/bulk-upload/{batch}/errors.csv — downloadable error report. */
    public function uploadErrors(Request $request, $batch)
    {
        $shopId = $this->resolveShopId($request);
        $b = PriceImportBatch::where('shop_id', $shopId)->findOrFail($batch);
        $rows = (array) ($b->errors ?? []);

        $callback = function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['line', 'error']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['line'] ?? ($r['index'] ?? ''), $r['error'] ?? '']);
            }
            fclose($out);
        };
        return response()->streamDownload($callback, "import-errors-{$b->id}.csv", ['Content-Type' => 'text/csv']);
    }

    /** GET /vendor/service-areas — the caller's served cities. */
    public function serviceAreas(Request $request)
    {
        $shopId = $this->resolveShopId($request);
        return VendorServiceArea::where('shop_id', $shopId)->orderBy('city')->get();
    }

    /** POST /vendor/service-areas — add / update a served city. */
    public function addServiceArea(Request $request)
    {
        $shopId = $this->resolveShopId($request);
        $request->validate([
            'city'             => 'required|string|max:120',
            'fulfillment_mode' => 'required|in:local,courier,both',
            'pincode'          => 'nullable|string|max:12',
            'eta_days'         => 'nullable|integer|min:0|max:60',
        ]);
        $area = VendorServiceArea::updateOrCreate(
            ['shop_id' => $shopId, 'city' => trim((string) $request->city), 'pincode' => $request->pincode],
            ['fulfillment_mode' => $request->fulfillment_mode, 'eta_days' => $request->eta_days, 'is_active' => true]
        );
        // Whole-catalogue rebuild → queued (deduped per shop); the projection
        // is a cache, seconds of staleness is fine.
        \Marvel\Jobs\RecomputeShopAvailabilityJob::dispatch($shopId);
        return $area;
    }

    /** DELETE /vendor/service-areas/{id} — stop serving a city. */
    public function deleteServiceArea(Request $request, $id)
    {
        $shopId = $this->resolveShopId($request);
        $area = VendorServiceArea::where('shop_id', $shopId)->findOrFail($id);
        $area->delete();
        \Marvel\Jobs\RecomputeShopAvailabilityJob::dispatch($shopId);
        return ['success' => true];
    }
}
