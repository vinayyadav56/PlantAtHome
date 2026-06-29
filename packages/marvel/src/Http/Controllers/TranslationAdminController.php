<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\TranslationCache;
use Marvel\Jobs\TranslateEntityJob;
use Marvel\Translation\TranslationManager;

/**
 * Super-admin analytics + management for the translation engine.
 * Feeds the admin Language-Management dashboard (coverage, queue, cost, cache
 * hit ratio, missing list) and drives bulk/selected retranslation + cache clear.
 */
class TranslationAdminController extends CoreController
{
    /** Translatable entity registry (extend to add a content type — no other code change). */
    public const ENTITIES = [
        'product'  => Product::class,
        'category' => Category::class,
    ];

    /** Dashboard metrics: coverage %, queue depth, failed, provider, cost, cache hit ratio. */
    public function stats(Request $request)
    {
        $default = config('translation.default_language', 'en');
        $languages = array_values(array_filter(config('translation.languages', []), fn($l) => $l !== $default));

        // Per-(type,language) coverage.
        $coverage = [];
        $totals = [];
        foreach (self::ENTITIES as $key => $model) {
            $totals[$key] = (int) $model::where('language', $default)->count();
        }
        $rows = DB::table('translations_cache')
            ->select('translatable_type', 'language', 'status', DB::raw('count(*) as c'))
            ->groupBy('translatable_type', 'language', 'status')
            ->get();

        $byType = [];
        foreach ($rows as $r) {
            $key = $this->keyForType($r->translatable_type);
            if (!$key) continue;
            $byType[$key][$r->language][$r->status] = (int) $r->c;
        }

        foreach (self::ENTITIES as $key => $model) {
            foreach ($languages as $lang) {
                $translated = $byType[$key][$lang]['translated'] ?? 0;
                $outdated = $byType[$key][$lang]['outdated'] ?? 0;
                $failed = $byType[$key][$lang]['failed'] ?? 0;
                $total = max($totals[$key], 1);
                $coverage[] = [
                    'type' => $key,
                    'language' => $lang,
                    'total' => $totals[$key],
                    'translated' => $translated,
                    'outdated' => $outdated,
                    'failed' => $failed,
                    'coverage_percent' => round(($translated / $total) * 100, 1),
                ];
            }
        }

        $hit = (int) Cache::get('txn:stats:hit', 0);
        $miss = (int) Cache::get('txn:stats:miss', 0);
        $hitRatio = ($hit + $miss) > 0 ? round($hit / ($hit + $miss) * 100, 1) : 0.0;

        $manager = app(TranslationManager::class);
        $provider = config('translation.default_provider', 'google');
        try {
            $activeRow = \Marvel\Database\Models\TranslationProviderConfig::where('is_active', true)->where('enabled', true)->first();
            if ($activeRow) $provider = $activeRow->provider;
        } catch (\Throwable $e) {
        }

        // Rough cost estimate for pending/outdated work (chars × provider rate).
        $pendingChars = (int) (DB::table('translations_cache')->whereIn('status', ['pending', 'outdated', 'failed'])->count() * 80);
        $rate = (float) config('translation.cost_per_million_chars.' . $provider, 20.0);
        $estCost = round(($pendingChars / 1_000_000) * $rate, 4);

        return [
            'enabled' => (bool) config('translation.enabled', true),
            'default_language' => $default,
            'languages' => $languages,
            'provider' => $provider,
            'available_providers' => $manager->available(),
            'totals' => $totals,
            'coverage' => $coverage,
            'queue' => [
                'pending' => (int) DB::table('translations_cache')->where('status', 'pending')->count(),
                'outdated' => (int) DB::table('translations_cache')->where('status', 'outdated')->count(),
                'failed' => (int) DB::table('translations_cache')->where('status', 'failed')->count(),
                'depth' => $this->queueDepth(),
            ],
            'cache' => [
                'hits' => $hit,
                'misses' => $miss,
                'hit_ratio_percent' => $hitRatio,
            ],
            'cost' => [
                'estimated_pending_usd' => $estCost,
                'string_cache_entries' => (int) DB::table('translation_string_cache')->count(),
            ],
        ];
    }

    /** Paginated list of entities missing / outdated for a language. */
    public function missing(Request $request)
    {
        $type = $request->get('type', 'product');
        $language = $request->get('language');
        $model = self::ENTITIES[$type] ?? Product::class;
        $default = config('translation.default_language', 'en');
        $limit = min(max((int) $request->get('limit', 20), 1), 100);

        $translatedIds = TranslationCache::where('translatable_type', $model)
            ->where('language', $language)
            ->where('status', 'translated')
            ->pluck('translatable_id');

        $query = $model::where('language', $default)->whereNotIn('id', $translatedIds);

        return $query->paginate($limit);
    }

    /** Enqueue translation for specific entity ids + languages. */
    public function retranslate(Request $request)
    {
        $type = $request->get('type', 'product');
        $model = self::ENTITIES[$type] ?? Product::class;
        $ids = (array) $request->get('ids', []);
        $languages = $this->targetLanguages($request->get('languages'));
        $queue = config('translation.queue', 'translations');

        $count = 0;
        foreach ($ids as $id) {
            foreach ($languages as $lang) {
                TranslateEntityJob::dispatch($model, (int) $id, $lang)->onQueue($queue);
                $count++;
            }
        }
        return ['dispatched' => $count, 'languages' => $languages];
    }

    /** Enqueue translation for ALL entities of a type into the given languages. */
    public function bulkRetranslate(Request $request)
    {
        $type = $request->get('type', 'product');
        $model = self::ENTITIES[$type] ?? Product::class;
        $languages = $this->targetLanguages($request->get('languages'));
        $default = config('translation.default_language', 'en');
        $queue = config('translation.queue', 'translations');

        $count = 0;
        $model::where('language', $default)->select('id')->chunkById(500, function ($rows) use ($model, $languages, $queue, &$count) {
            foreach ($rows as $row) {
                foreach ($languages as $lang) {
                    TranslateEntityJob::dispatch($model, (int) $row->id, $lang)->onQueue($queue);
                    $count++;
                }
            }
        });
        return ['dispatched' => $count, 'type' => $type, 'languages' => $languages];
    }

    /** Clear the Redis translation cache (and optionally requeue). */
    public function clearCache(Request $request)
    {
        // Reset hit/miss counters; the per-entity keys expire/rebuild on demand.
        Cache::forget('txn:stats:hit');
        Cache::forget('txn:stats:miss');
        // Bump response cache versions so storefront refetches.
        foreach (['products', 'categories'] as $g) {
            Cache::forever("{$g}:ver", ((int) Cache::get("{$g}:ver", 1)) + 1);
        }
        return ['cleared' => true];
    }

    /** Mark a translation row reviewed (protects manual edits). */
    public function markReviewed(Request $request)
    {
        $id = (int) $request->get('id');
        TranslationCache::whereKey($id)->update(['is_reviewed' => (bool) $request->get('is_reviewed', true)]);
        return ['updated' => true];
    }

    protected function targetLanguages($input): array
    {
        $default = config('translation.default_language', 'en');
        $all = array_values(array_filter(config('translation.languages', []), fn($l) => $l !== $default));
        if (empty($input)) return $all;
        $list = is_array($input) ? $input : explode(',', (string) $input);
        return array_values(array_intersect(array_map('trim', $list), $all));
    }

    protected function keyForType(string $fqcn): ?string
    {
        foreach (self::ENTITIES as $key => $model) {
            if ($model === $fqcn) return $key;
        }
        return null;
    }

    protected function queueDepth(): int
    {
        try {
            return (int) Queue::size(config('translation.queue', 'translations'));
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
