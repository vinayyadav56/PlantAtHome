<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Marvel\Database\Models\PriceImportBatch;
use Marvel\Database\Models\VendorProductPrice;
use Marvel\Imports\VendorPriceSheetImport;

/**
 * Admin-only vendor price-sheet management. Upload an Excel/CSV of a vendor's
 * (shop's) costs for a period; rows upsert into vendor_product_prices and an
 * audit batch is recorded. cost=0 ⇒ product flagged unavailable ("available in 6h").
 */
class PriceSheetController extends CoreController
{
    /** Admin: import a vendor price sheet (.xlsx/.csv). */
    public function import(Request $request)
    {
        $request->validate([
            'shop_id'     => 'required|integer|exists:Marvel\Database\Models\Shop,id',
            'period_type' => 'nullable|in:weekly,monthly',
        ]);

        $files = $request->file();
        if (empty($files)) {
            return response()->json(['message' => 'No file uploaded.'], 422);
        }
        $uploaded = $files['file'] ?? ($files['csv'] ?? current($files));

        // Only a spreadsheet, and store it on the PRIVATE disk (audit/re-parse only —
        // never publicly served), so an uploaded file can't become an executable/script
        // artifact at a public URL.
        $ext = strtolower($uploaded->getClientOriginalExtension() ?: 'xlsx');
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            return response()->json(['message' => 'Only .xlsx, .xls or .csv files are allowed.'], 422);
        }

        $shopId     = (int) $request->input('shop_id');
        // Default to 'monthly' to match the vendor self-serve standing row — a mismatched default
        // would fork a second vendor_product_prices row for the same product+vendor.
        $periodType = $request->input('period_type', 'monthly');
        $from       = $request->input('effective_from');
        $to         = $request->input('effective_to');

        // Store the original file for audit (private disk).
        $path = $uploaded->storeAs(
            'price-sheets',
            'vendor-' . $shopId . '-' . $periodType . '-' . time() . '.' . $ext,
            'local'
        );

        $batch = PriceImportBatch::create([
            'uploaded_by'    => optional($request->user())->id,
            'shop_id'        => $shopId,
            'period_type'    => $periodType,
            'effective_from' => $from,
            'effective_to'   => $to,
            'file'           => $path,
            'status'         => 'processing',
        ]);

        try {
            $import = new VendorPriceSheetImport($shopId, $periodType, $from, $to, $batch->id, optional($request->user())->id);
            Excel::import($import, $uploaded);
        } catch (\Throwable $e) {
            $batch->update(['status' => 'failed', 'errors' => [['line' => 0, 'error' => $e->getMessage()]]]);
            return response()->json(['message' => 'Could not read the sheet.', 'error' => $e->getMessage(), 'batch' => $batch], 422);
        }

        $batch->update([
            'row_count'   => $import->rowCount,
            'error_count' => $import->errorCount,
            'errors'      => $import->errors,
            'status'      => $import->errorCount && !$import->rowCount ? 'failed' : 'completed',
        ]);

        return response()->json([
            'message'   => "Imported {$import->rowCount} rows" . ($import->errorCount ? ", {$import->errorCount} skipped." : '.'),
            'imported'  => $import->rowCount,
            'skipped'   => $import->errorCount,
            'errors'    => $import->errors,
            'batch'     => $batch->fresh(),
        ]);
    }

    /** Admin: upload-batch history (audit). */
    public function batches(Request $request)
    {
        $limit = (int) ($request->limit ?? 20);
        $query = PriceImportBatch::with('shop:id,name,slug')->orderByDesc('id');
        if ($request->filled('shop_id')) {
            $query->where('shop_id', $request->shop_id);
        }
        return $query->paginate($limit);
    }

    /** Admin: current vendor cost rows (review). cost is admin-only. */
    public function prices(Request $request)
    {
        $limit = (int) ($request->limit ?? 30);
        $query = VendorProductPrice::with(['shop:id,name,slug', 'product:id,name,slug,sku'])
            ->orderByDesc('id');
        foreach (['shop_id', 'product_id', 'period_type'] as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->input($f));
            }
        }
        if ($request->filled('is_available')) {
            $query->where('is_available', $request->boolean('is_available'));
        }
        return $query->paginate($limit);
    }

    /** Admin: remove a vendor cost row (soft delete). */
    public function destroy($id)
    {
        $row = VendorProductPrice::findOrFail($id);
        $row->delete();
        return $row;
    }

    /**
     * Admin: every vendor supplying a master product — price, stock, city,
     * fulfillment mode, availability — for the catalog expand + order assignment.
     */
    public function productVendors(Request $request, $productId)
    {
        $service = new \Marvel\Services\AvailabilityService();
        $vendors = $service->vendorsForProduct(
            (int) $productId,
            $request->filled('variation_option_id') ? (int) $request->input('variation_option_id') : null
        );

        // Variant titles — without them a 3-size × 2-vendor product renders six
        // identical-looking rows and the panel is unreadable.
        $variantIds = collect($vendors)->pluck('variation_option_id')->filter()->unique()->values();
        $titles = $variantIds->isEmpty()
            ? collect()
            : \Illuminate\Support\Facades\DB::table('variation_options')
                ->whereIn('id', $variantIds)->pluck('title', 'id');
        $vendors = array_map(function ($v) use ($titles) {
            $v['variant_title'] = $v['variation_option_id']
                ? ($titles[$v['variation_option_id']] ?? null)
                : null;
            return $v;
        }, $vendors);

        return [
            'product_id' => (int) $productId,
            'vendors'    => $vendors,
            // What the CUSTOMER sees, per city per variant, straight from the
            // projection. The margin is not recoverable from it (only the
            // post-margin price is stored), so it is resolved alongside.
            'city_prices' => $this->cityPricesFor((int) $productId, $titles),
        ];
    }

    /** @return array<int,array<string,mixed>> projection rows + resolved margin */
    private function cityPricesFor(int $productId, $titles): array
    {
        try {
            $product = \Marvel\Database\Models\Product::find($productId);
            $typeId = ($product && $product->type_id) ? (int) $product->type_id : null;
            $resolver = new \Marvel\Services\MarginResolver();

            return \Marvel\Database\Models\ProductCityAvailability::where('product_id', $productId)
                ->orderBy('city')->orderBy('variation_option_id')
                ->get()
                ->map(fn ($r) => [
                    'city'                => $r->city,
                    'variation_option_id' => (int) $r->variation_option_id,
                    'variant_title'       => $r->variation_option_id
                        ? ($titles[$r->variation_option_id] ?? null)
                        : 'All sizes',
                    'display_price'  => $r->display_price !== null ? (float) $r->display_price : null,
                    'stock'          => $r->effectiveStock(),
                    'stock_override' => $r->stock_override,
                    'vendor_count'   => (int) $r->vendor_count,
                    'margin_percent' => $resolver->marginPercent($r->city, $typeId),
                    'has_local'      => (bool) $r->has_local,
                    'has_courier'    => (bool) $r->has_courier,
                ])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
