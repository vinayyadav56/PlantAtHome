<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\MarketWatchlistItem;
use Marvel\Services\MarketIntelligenceService;

/**
 * Admin Market Intelligence: competitor-catalogue name import + price watchlist.
 * All routes are behind permission:super_admin + auth:sanctum (Rest/Routes.php).
 */
class MarketIntelligenceController extends CoreController
{
    // ── Price watchlist ───────────────────────────────────────────────────────

    /** GET market/watchlist — tracked items with their latest price. */
    public function index(): JsonResponse
    {
        $items = MarketWatchlistItem::withCount('snapshots')
            ->orderBy('title')
            ->get();

        return response()->json($items);
    }

    /** GET market/search?q= — fuzzy competitor search for the add-picker. */
    public function search(Request $request, MarketIntelligenceService $service): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        return response()->json($service->search($q, (int) $request->query('limit', 15)));
    }

    /** POST market/watchlist — start tracking a competitor listing. */
    public function store(Request $request, MarketIntelligenceService $service): JsonResponse
    {
        $data = $request->validate([
            'doc_id'      => ['required', 'string', 'max:255'],
            'title'       => ['required', 'string', 'max:512'],
            'source_site' => ['nullable', 'string', 'max:64'],
        ]);

        $item = $service->addToWatchlist($data['doc_id'], $data['title'], $data['source_site'] ?? null);

        return response()->json($item, 201);
    }

    /** DELETE market/watchlist/{id} — stop tracking (snapshots cascade). */
    public function destroy(int $id): JsonResponse
    {
        MarketWatchlistItem::where('id', $id)->delete();

        return response()->json(['message' => 'Removed from watchlist.']);
    }

    /** POST market/refresh — snapshot every watchlist item now. */
    public function refresh(MarketIntelligenceService $service): JsonResponse
    {
        return response()->json($service->refreshWatchlist());
    }

    /** GET market/price-history — per-item price time series for charting. */
    public function priceHistory(MarketIntelligenceService $service): JsonResponse
    {
        return response()->json($service->priceHistory());
    }

    // ── Catalogue name import ─────────────────────────────────────────────────

    /** GET market/import-preview — dry run (counts + sample), no writes. */
    public function importPreview(MarketIntelligenceService $service): JsonResponse
    {
        try {
            return response()->json($service->importPreview());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not reach the market service.'], 502);
        }
    }

    /** POST market/import — create DRAFT products for new competitor names. */
    public function importNames(MarketIntelligenceService $service): JsonResponse
    {
        try {
            $result = $service->importNames();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Import failed: '.$e->getMessage()], 502);
        }

        return response()->json($result);
    }
}
