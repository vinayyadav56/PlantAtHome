<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
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
            return $requested;
        }
        $first = $shops->first();
        if (!$first) {
            abort(422, 'No vendor shop is associated with your account.');
        }
        return (int) $first->id;
    }

    /** GET /vendor/catalog-search — master products to attach (+ what I already supply). */
    public function catalogSearch(Request $request)
    {
        $shopId = $this->resolveShopId($request);
        $limit = min(50, max(1, (int) ($request->limit ?? 20)));

        $query = Product::query()
            ->where('status', ProductStatus::PUBLISH)
            ->with(['variation_options:id,product_id,title,sku,price']);

        if ($request->filled('q')) {
            $term = trim((string) $request->q);
            $query->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%"));
        }
        if ($request->filled('type_id')) {
            $query->where('type_id', (int) $request->type_id);
        }
        if ($request->filled('category_id')) {
            $cid = (int) $request->category_id;
            $query->whereHas('categories', fn ($c) => $c->where('categories.id', $cid));
        }

        $page = $query->select('id', 'name', 'slug', 'sku', 'image', 'type_id', 'product_type', 'price')
            ->orderBy('name')->paginate($limit);

        // Annotate already-attached + my price/stock per product for this vendor.
        $ids = collect($page->items())->pluck('id')->all();
        $mine = VendorProductPrice::where('shop_id', $shopId)->whereIn('product_id', $ids)
            ->get()->groupBy('product_id');
        $page->getCollection()->transform(function ($p) use ($mine) {
            $rows = $mine[$p->id] ?? collect();
            $p->already_attached = $rows->isNotEmpty();
            $p->my_inventory = $rows->map(fn ($r) => [
                'id'                   => $r->id,
                'variation_option_id'  => $r->variation_option_id,
                'vendor_selling_price' => $r->vendor_selling_price !== null ? (float) $r->vendor_selling_price : null,
                'stock_qty'            => (int) ($r->stock_qty ?? 0),
                'fulfillment_mode'     => $r->fulfillment_mode,
            ])->values();
            return $p;
        });
        return $page;
    }

    /** POST /vendor/inventory/bulk-attach — multi-select save (price + stock per item). */
    public function bulkAttach(Request $request)
    {
        $request->validate([
            'items'                          => 'required|array|min:1|max:500',
            'items.*.product_id'             => 'required_without:items.*.sku',
            'items.*.vendor_selling_price'   => 'nullable|numeric|min:0',
            'items.*.cost_price'             => 'nullable|numeric|min:0',
            'items.*.stock_qty'              => 'nullable|integer|min:0',
            'items.*.fulfillment_mode'       => 'nullable|in:local,courier,both',
        ]);
        $shopId = $this->resolveShopId($request);
        $result = (new VendorInventoryWriter())->writeItems($shopId, $request->input('items'), [
            'user_id' => optional($request->user())->id,
        ]);
        return response()->json($result);
    }

    /** GET /vendor/inventory — the caller's attached rows. */
    public function inventory(Request $request)
    {
        $shopId = $this->resolveShopId($request);
        $limit = min(100, max(1, (int) ($request->limit ?? 30)));
        $query = VendorProductPrice::with(['product:id,name,slug,sku,image'])
            ->where('shop_id', $shopId)->orderByDesc('id');
        if ($request->filled('search')) {
            $term = trim((string) $request->search);
            $query->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%"));
        }
        return $query->paginate($limit);
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
        $request->validate([
            'vendor_selling_price' => 'nullable|numeric|min:0',
            'cost_price'           => 'nullable|numeric|min:0',
            'stock_qty'            => 'nullable|integer|min:0',
            'fulfillment_mode'     => 'nullable|in:local,courier,both',
        ]);

        if ($request->has('vendor_selling_price')) {
            $row->vendor_selling_price = $request->vendor_selling_price !== null ? (float) $request->vendor_selling_price : null;
        }
        if ($request->has('cost_price')) {
            $row->cost_price = $request->cost_price !== null ? (float) $request->cost_price : null;
        }
        if ($request->has('stock_qty')) {
            $row->stock_qty = max(0, (int) $request->stock_qty);
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
            'status'      => 'completed',
        ]);

        try {
            $import = new VendorPriceSheetImport($shopId, $periodType, null, null, $batch->id, optional($request->user())->id);
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
        (new AvailabilityService())->recomputeForShop($shopId);
        return $area;
    }

    /** DELETE /vendor/service-areas/{id} — stop serving a city. */
    public function deleteServiceArea(Request $request, $id)
    {
        $shopId = $this->resolveShopId($request);
        $area = VendorServiceArea::where('shop_id', $shopId)->findOrFail($id);
        $area->delete();
        (new AvailabilityService())->recomputeForShop($shopId);
        return ['success' => true];
    }
}
