<?php

namespace Marvel\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Marvel\Database\Models\MarketPriceSnapshot;
use Marvel\Database\Models\MarketWatchlistItem;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\Type;

/**
 * Talks to the external competitor-catalogue service (NurseryLive + Ugaoo scrape)
 * and powers the admin Market Intelligence page:
 *   - importNames(): bulk-import cleaned, deduped plant names into the master
 *     catalogue as DRAFT products (never overwrites existing products).
 *   - search()/addToWatchlist()/refreshWatchlist()/priceHistory(): price tracking.
 *
 * The upstream `q` full-text param is broken (returns 0), so title search uses the
 * working `reverse_name` + `fuzzy` matcher (it matches forward text too).
 */
class MarketIntelligenceService
{
    private function http(): PendingRequest
    {
        $key = (string) config('services.market_intelligence.api_key');

        return Http::timeout((int) config('services.market_intelligence.timeout', 30))
            ->acceptJson()
            ->withHeaders(array_filter(['X-Api-Key' => $key ?: null]));
    }

    private function base(): string
    {
        return rtrim((string) config('services.market_intelligence.url'), '/');
    }

    // ── Upstream reads ────────────────────────────────────────────────────────

    /** Fuzzy title search (deduped by title) for the "add to watchlist" picker. */
    public function search(string $q, int $limit = 15): array
    {
        $resp = $this->http()->post($this->base().'/api/v1/plants/search', [
            'reverse_name'    => $q,
            'fuzzy'           => true,
            'fuzzy_threshold' => 0.4,
            'page_size'       => 60,
        ]);

        if (! $resp->successful()) {
            return [];
        }

        $seen = [];
        $out = [];
        foreach ($resp->json('items', []) as $it) {
            $title = trim((string) ($it['title'] ?? ''));
            if ($title === '' || $this->isCombo($it)) {
                continue;               // skip combos/bundles — track single plants
            }
            $key = mb_strtolower($title);
            if (isset($seen[$key])) {
                continue;               // upstream is ~14x duplicated — one row per title
            }
            $seen[$key] = true;
            $out[] = $this->normalize($it);
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** Current price for a single listing (by upstream doc id). Null if gone. */
    public function getListing(string $docId): ?array
    {
        $resp = $this->http()->get($this->base().'/api/v1/plants/'.rawurlencode($docId));
        if (! $resp->successful()) {
            return null;
        }
        $data = $resp->json('data', $resp->json());

        return is_array($data) && ! empty($data['id']) ? $this->normalize($data) : null;
    }

    /** Every listing (paginated), for the name import. Optionally scoped by source. */
    public function fetchAllListings(?string $source = null): array
    {
        $all = [];
        $page = 1;
        do {
            $query = ['page_size' => 200, 'page' => $page];
            if ($source) {
                $query['source_site'] = $source;
            }
            $resp = $this->http()->get($this->base().'/api/v1/plants', $query);
            if (! $resp->successful()) {
                break;
            }
            $items = $resp->json('items', []);
            $total = (int) $resp->json('total', 0);
            $all = array_merge($all, $items);
            $page++;
        } while (count($all) < $total && ! empty($items) && $page <= 60);

        return $all;
    }

    /**
     * Full NurseryLive catalogue via its public Shopify products feed
     * (/products.json). Unlike the partial intelligence scrape, this is the
     * COMPLETE product list. Filtered to plant product-types.
     *
     * @return array<int,array{title:string,plant_form:string,price_current:?float,source_site:string}>
     */
    public function fetchNurseryLiveCatalog(bool $includeCombos = false): array
    {
        // Crawl once, cache 10 min — so import-preview + import don't double-crawl
        // (the store rate-limits bursts). Cache holds plants AND plant-combos;
        // combos are filtered out here unless requested.
        $all = Cache::remember('market_intel:nurserylive_catalog', 600, fn () => $this->crawlNurseryLive());

        return $includeCombos
            ? $all
            : array_values(array_filter($all, fn ($x) => ($x['plant_form'] ?? 'single') !== 'combo'));
    }

    /** Polite paged crawl of NurseryLive's Shopify products feed (plants + plant-combos). */
    private function crawlNurseryLive(): array
    {
        $base = rtrim((string) config('services.market_intelligence.nurserylive_url', 'https://nurserylive.com'), '/');
        $out = [];
        for ($page = 1; $page <= 60; $page++) {
            $resp = Http::timeout(30)
                ->retry(3, 2500, throw: false)   // ride out 429/503 rate-limits
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'])
                ->get($base.'/products.json', ['limit' => 250, 'page' => $page]);
            if (! $resp->successful()) {
                break;
            }
            $products = $resp->json('products', []);
            if (empty($products)) {
                break;
            }
            foreach ($products as $p) {
                $type = mb_strtolower((string) ($p['product_type'] ?? ''));
                $isPlant = in_array($type, ['plants', 'nfs-plants'], true);
                $isPlantCombo = $type === 'combo packs - plants';
                if (! $isPlant && ! $isPlantCombo) {
                    continue;   // skip seeds / planters / pebbles / tools / gifts …
                }
                $price = $p['variants'][0]['price'] ?? null;
                $out[] = [
                    'title'         => (string) ($p['title'] ?? ''),
                    'plant_form'    => $isPlantCombo ? 'combo' : 'single',
                    'price_current' => is_numeric($price) ? (float) $price : null,
                    'source_site'   => 'nurserylive',
                ];
            }
            usleep(350000);   // ~0.35s between pages — stay under the store's rate limit
        }

        return $out;
    }

    private function normalize(array $it): array
    {
        return [
            'doc_id'           => $it['id'] ?? null,
            'title'            => $it['title'] ?? '',
            'source_site'      => $it['source_site'] ?? null,
            'price_current'    => isset($it['price_current']) ? (float) $it['price_current'] : null,
            'price_mrp'        => isset($it['price_mrp']) ? (float) $it['price_mrp'] : null,
            'discount_percent' => isset($it['discount_percent']) ? (float) $it['discount_percent'] : null,
            'sold_count'       => $it['sold_count'] ?? null,
            'plant_form'       => $it['plant_form'] ?? null,
        ];
    }

    // ── Name cleaning (conservative — trailing noise only, never mid-title) ────

    public function cleanName(string $title): string
    {
        $t = trim($title);
        // trailing "in a [n inch] pot/bowl/vase/..." and everything after
        $t = preg_replace('/\bin\s+a?\s*[\d.]*\s*(inch|")?\s*(pot|bowl|vase|planter|container|bottle|jar|basket|tray|bag)\b.*$/i', '', $t);
        // trailing "with pot/planter/pebbles/..."
        $t = preg_replace('/\b(with|in)\s+(pot|pots|planter|bowl|vase|pebbles|self[- ]?watering)\b.*$/i', '', $t);
        // trailing size measurement
        $t = preg_replace('/\b[\d.]+\s*(inch|cm|mm|ft|feet|")\b.*$/i', '', $t);
        // trailing "pack/set/combo/buy N ..."
        $t = preg_replace('/\b(pack of|set of|combo of|buy)\s*\d+.*$/i', '', $t);
        // trailing "(Pack of N)" / "- Gift"
        $t = preg_replace('/\s*\(?\s*pack of\s*\d+\s*\)?\s*$/i', '', $t);
        $t = preg_replace('/\s*[-–]\s*gift\s*$/i', '', $t);
        // trailing size tier
        $t = preg_replace('/\s*[-–]\s*(XXL|XL|L|M|S|Large|Medium|Small|Extra Large|Mini)\s*$/i', '', $t);
        // trailing "- Plant / , Plant / sapling / seeds / bulbs" — REQUIRE a separator
        // so intrinsic names like "Money Plant" / "Snake Plant" keep their "Plant".
        $t = preg_replace('/\s*[-–,]\s*(plants?|saplings?|seeds?|bulbs?)\s*$/i', '', $t);
        // tidy
        $t = preg_replace('/\s{2,}/', ' ', (string) $t);
        $t = trim((string) $t, " -,–\t");

        return $t;
    }

    private function isCombo(array $it): bool
    {
        // NB: the upstream `plant_form` field is unreliable (it tags ~94% of
        // listings "combo"), so detect bundles by TITLE only.
        return (bool) preg_match('/\b(bundle|combo|set of|pack of|collection|top\s+\d+)\b/i', (string) ($it['title'] ?? ''));
    }

    /**
     * Cleaned, deduped plant names from the upstream catalogue.
     *
     * @param  string|null  $source        nurserylive | ugaoo | null (all)
     * @param  bool         $includeCombos  keep bundles/combos too
     */
    private function candidateNames(?string $source = null, bool $includeCombos = false): array
    {
        // 'nurserylive_full' pulls the COMPLETE NurseryLive catalogue (Shopify feed),
        // already filtered to plant types; every other source uses the intelligence API.
        $fullCatalog = $source === 'nurserylive_full';
        $items = $fullCatalog
            ? $this->fetchNurseryLiveCatalog($includeCombos)
            : $this->fetchAllListings($source);

        $seen = [];
        foreach ($items as $it) {
            if (! $fullCatalog && ! $includeCombos && $this->isCombo($it)) {
                continue;
            }
            $name = $this->cleanName((string) ($it['title'] ?? ''));
            if (mb_strlen($name) < 2) {
                $name = trim((string) ($it['title'] ?? ''));
            }
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            $seen[$key] = $seen[$key] ?? $name;
        }
        ksort($seen);

        return array_values($seen);
    }

    // ── Purpose 1: import names into the master catalogue as DRAFTS ────────────

    /** slug => display-name map, deduped by slug, for a source/scope. */
    private function candidateSlugMap(?string $source, bool $includeCombos): array
    {
        $bySlug = [];
        foreach ($this->candidateNames($source, $includeCombos) as $name) {
            $slug = Str::slug($name);
            if ($slug !== '') {
                $bySlug[$slug] ??= $name;
            }
        }

        return $bySlug;
    }

    /** Which candidate slugs already exist in the catalogue (batched). */
    private function existingSlugs(array $slugs): array
    {
        if (empty($slugs)) {
            return [];
        }

        return Product::withTrashed()
            ->whereIn('slug', $slugs)
            ->where('language', 'en')
            ->pluck('slug')
            ->unique()
            ->all();
    }

    /** Dry run — what an import would do, without writing. */
    public function importPreview(?string $source = null, bool $includeCombos = false): array
    {
        $bySlug = $this->candidateSlugMap($source, $includeCombos);
        $total = count($bySlug);
        foreach ($this->existingSlugs(array_keys($bySlug)) as $slug) {
            unset($bySlug[$slug]);
        }

        return [
            'total_candidates' => $total,
            'already_in_catalog' => $total - count($bySlug),
            'to_create' => count($bySlug),
            'sample' => array_slice(array_values($bySlug), 0, 40),
        ];
    }

    /**
     * Create DRAFT products for names not already in the master catalogue.
     * Bulk-inserts in chunks (handles the full ~2,600-plant catalogue quickly);
     * never overwrites an existing product.
     */
    public function importNames(?string $source = null, bool $includeCombos = false): array
    {
        $shopId = Shop::masterId();
        $typeId = Type::where('slug', 'plants')->where('language', 'en')->value('id')
            ?? Type::where('language', 'en')->value('id');

        $bySlug = $this->candidateSlugMap($source, $includeCombos);
        $total = count($bySlug);
        foreach ($this->existingSlugs(array_keys($bySlug)) as $slug) {
            unset($bySlug[$slug]);   // skip anything already in the catalogue
        }

        $now = now();
        $rows = [];
        foreach ($bySlug as $slug => $name) {
            $rows[] = [
                'name'         => $name,
                'slug'         => $slug,
                'type_id'      => $typeId,
                'shop_id'      => $shopId,
                'unit'         => '1 Plant',
                'status'       => 'draft',        // hidden: storefront queries status=publish
                'product_type' => 'simple',
                'language'     => 'en',
                'price'        => 0,
                'sale_price'   => 0,
                'min_price'    => 0,
                'max_price'    => 0,
                'quantity'     => 0,
                'in_stock'     => 0,
                'is_taxable'   => 0,
                'visibility'   => 'visibility_public',
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('products')->insert($chunk);
        }

        return [
            'created' => count($rows),
            'skipped_existing' => $total - count($rows),
            'total_candidates' => $total,
            'created_names' => array_values($bySlug),
        ];
    }

    // ── Purpose 2: price watchlist + snapshots ────────────────────────────────

    public function addToWatchlist(string $docId, string $title, ?string $sourceSite): MarketWatchlistItem
    {
        $item = MarketWatchlistItem::firstOrCreate(
            ['doc_id' => $docId],
            ['title' => $title, 'source_site' => $sourceSite],
        );

        // Capture an initial price point immediately so the chart isn't empty.
        $this->snapshot($item);

        return $item->refresh();
    }

    /** Take a fresh price point for one item (returns the snapshot or null). */
    public function snapshot(MarketWatchlistItem $item): ?MarketPriceSnapshot
    {
        $listing = $this->getListing($item->doc_id);
        if ($listing === null) {
            return null;
        }

        $snap = MarketPriceSnapshot::create([
            'watchlist_id'     => $item->id,
            'price_current'    => $listing['price_current'],
            'price_mrp'        => $listing['price_mrp'],
            'discount_percent' => $listing['discount_percent'],
            'captured_at'      => now(),
        ]);

        $item->update([
            'last_price'            => $listing['price_current'],
            'last_price_mrp'        => $listing['price_mrp'],
            'last_discount_percent' => $listing['discount_percent'],
            'last_refreshed_at'     => now(),
        ]);

        return $snap;
    }

    /** Snapshot every watchlist item. Returns {refreshed, failed}. */
    public function refreshWatchlist(): array
    {
        $refreshed = 0;
        $failed = 0;
        foreach (MarketWatchlistItem::all() as $item) {
            if ($this->snapshot($item)) {
                $refreshed++;
            } else {
                $failed++;
            }
        }

        return ['refreshed' => $refreshed, 'failed' => $failed, 'captured_at' => now()->toIso8601String()];
    }

    /** Per-item price time series for charting. */
    public function priceHistory(): array
    {
        return MarketWatchlistItem::with(['snapshots' => fn ($q) => $q->orderBy('captured_at')])
            ->orderBy('title')
            ->get()
            ->map(fn (MarketWatchlistItem $item) => [
                'id'          => $item->id,
                'doc_id'      => $item->doc_id,
                'title'       => $item->title,
                'source_site' => $item->source_site,
                'last_price'  => $item->last_price,
                'points'      => $item->snapshots->map(fn (MarketPriceSnapshot $s) => [
                    'captured_at' => optional($s->captured_at)->toIso8601String(),
                    'date'        => optional($s->captured_at)->format('d M H:i'),
                    'price'       => $s->price_current,
                    'mrp'         => $s->price_mrp,
                ])->values(),
            ])
            ->values()
            ->all();
    }
}
