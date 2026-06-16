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
            'shop_id'     => 'required|integer',
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
        $periodType = $request->input('period_type', 'weekly');
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
            'status'         => 'completed',
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
        return [
            'product_id' => (int) $productId,
            'vendors'    => $service->vendorsForProduct(
                (int) $productId,
                $request->filled('variation_option_id') ? (int) $request->input('variation_option_id') : null
            ),
        ];
    }
}
