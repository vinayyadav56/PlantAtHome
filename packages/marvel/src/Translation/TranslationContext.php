<?php

namespace Marvel\Translation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Request-scoped translation overlay engine.
 *
 * Bound as a scoped singleton. The HasTranslationOverlay trait registers every
 * retrieved model here (no query — just records the id as "pending"). The first
 * time a translated attribute is read during serialization, we bulk-resolve ALL
 * pending ids of that type in ONE round-trip (Redis MGET → DB fallback), so a
 * 100-row page (with nested relations) costs one query per (type, language),
 * never N+1.
 *
 * Misses fall back to the canonical English value (never blank) and, when lazy
 * translation is on, enqueue a background job (deduped + in-flight-locked so a
 * traffic burst on a cold entity enqueues once, not N times).
 *
 * Only `translated`-status rows are served. `outdated` (source changed) rows are
 * treated as a miss → English is shown until retranslation completes, honouring
 * the "never serve stale translations" requirement.
 */
class TranslationContext
{
    protected ?string $language = null;

    /** [type => [id => true]] ids seen this request but not yet resolved. */
    protected array $pending = [];

    /** [type => [id => [field => translatedValue]]] resolved field maps. */
    protected array $loaded = [];

    /** [key => true] entities we've already enqueued this request (dedupe). */
    protected array $dispatched = [];

    public function setLanguage(?string $language): void
    {
        $this->language = $language;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    /** Is the overlay active for the current request? */
    public function isActive(): bool
    {
        if (!config('translation.enabled', true)) {
            return false;
        }
        $default = config('translation.default_language', 'en');
        return $this->language && $this->language !== $default
            && in_array($this->language, config('translation.languages', []), true);
    }

    /**
     * Record a retrieved model as a candidate for overlay. Cheap — no query.
     */
    public function register(Model $model): void
    {
        if (!$this->isActive() || !$model->getKey()) {
            return;
        }
        $type = get_class($model);
        $id = (int) $model->getKey();
        if (isset($this->loaded[$type][$id]) || isset($this->pending[$type][$id])) {
            return;
        }
        $this->pending[$type][$id] = true;
    }

    /**
     * Return the translated value for $field on $model, or null to fall back to
     * the canonical English column. Triggers the one-shot bulk resolve.
     */
    public function translate(Model $model, string $field): ?string
    {
        if (!$this->isActive() || !$model->getKey()) {
            return null;
        }
        $type = get_class($model);
        $id = (int) $model->getKey();

        if (!isset($this->loaded[$type][$id])) {
            $this->resolveType($type);
        }

        $fields = $this->loaded[$type][$id] ?? [];
        $value = $fields[$field] ?? null;

        if ($value === null || $value === '') {
            // Miss: English fallback + lazy enqueue.
            $this->countMiss();
            $this->maybeDispatch($type, $id);
            return null;
        }

        $this->countHit();
        return $value;
    }

    /**
     * Bulk-resolve every pending id of $type in the current language:
     * Redis MGET first, DB (status=translated) for the misses, write-through.
     *
     * Fail-safe: ANY error here falls back to English — a translation problem
     * must NEVER break content delivery.
     */
    protected function resolveType(string $type): void
    {
        try {
            $this->resolveTypeInner($type);
        } catch (\Throwable $e) {
            // Mark every pending id of this type as resolved-empty so reads fall
            // back to the canonical English column and we don't retry on error.
            foreach (array_keys($this->pending[$type] ?? []) as $id) {
                $this->loaded[$type][$id] = [];
            }
            unset($this->pending[$type]);
        }
    }

    protected function resolveTypeInner(string $type): void
    {
        $ids = array_keys($this->pending[$type] ?? []);
        // The model that triggered this may not have fired `retrieved` (e.g.
        // freshly built); ensure its id is in the batch.
        if (empty($ids)) {
            return;
        }
        unset($this->pending[$type]);

        $prefix = config('translation.cache_prefix', 'txn');
        $short = $this->shortType($type);
        $lang = $this->language;

        // 1) Redis MGET.
        $keys = [];
        foreach ($ids as $id) {
            $keys[$id] = "{$prefix}:{$short}:{$id}:{$lang}";
        }
        $cached = [];
        try {
            $cached = Cache::many(array_values($keys));
        } catch (\Throwable $e) {
            $cached = [];
        }

        $missingIds = [];
        foreach ($ids as $id) {
            $val = $cached[$keys[$id]] ?? null;
            if (is_array($val)) {
                $this->loaded[$type][$id] = $val;
            } else {
                $missingIds[] = $id;
            }
        }

        // 2) DB fallback for cache-misses (one indexed IN query).
        if (!empty($missingIds)) {
            $rows = DB::table('translations_cache')
                ->where('translatable_type', $type)
                ->where('language', $lang)
                ->where('status', 'translated')
                ->whereIn('translatable_id', $missingIds)
                ->get(['translatable_id', 'translated_fields']);

            $found = [];
            foreach ($rows as $row) {
                $fields = json_decode($row->translated_fields, true) ?: [];
                $found[(int) $row->translatable_id] = $fields;
                $this->loaded[$type][(int) $row->translatable_id] = $fields;
                // write-through (permanent; invalidated explicitly on change).
                try {
                    Cache::forever($keys[(int) $row->translatable_id], $fields);
                } catch (\Throwable $e) {
                    // cache optional
                }
            }
            // ids with no translated row → record empty so we don't re-query.
            foreach ($missingIds as $id) {
                if (!isset($found[$id])) {
                    $this->loaded[$type][$id] = [];
                }
            }
        }
    }

    /**
     * Enqueue a translation for a missing entity (deduped + in-flight locked).
     */
    protected function maybeDispatch(string $type, int $id): void
    {
        if (!config('translation.lazy', true)) {
            return;
        }
        // Lazy translation needs a REAL async worker. Under the `sync` driver the
        // job would run inline during this read — blocking the response and, on a
        // provider error (e.g. missing key), breaking content delivery entirely.
        // On sync environments, translations are populated via marvel:translate-entities.
        if (config('queue.default') === 'sync') {
            return;
        }
        $key = "{$type}:{$id}:{$this->language}";
        if (isset($this->dispatched[$key])) {
            return;
        }
        $this->dispatched[$key] = true;

        // Belt-and-suspenders: a translation dispatch must NEVER break a read.
        try {
            // In-flight lock so a burst on a cold entity enqueues once.
            $lock = "txnlock:{$this->shortType($type)}:{$id}:{$this->language}";
            if (!Cache::add($lock, 1, 120)) {
                return;
            }
            if (class_exists(\Marvel\Jobs\TranslateEntityJob::class)) {
                \Marvel\Jobs\TranslateEntityJob::dispatch($type, $id, $this->language)
                    ->onQueue(config('translation.queue', 'translations'));
            }
        } catch (\Throwable $e) {
            // swallow — content delivery is more important than a translation
        }
    }

    public function shortType(string $type): string
    {
        return strtolower(class_basename($type));
    }

    protected function countHit(): void
    {
        try {
            Cache::increment('txn:stats:hit');
        } catch (\Throwable $e) {
        }
    }

    protected function countMiss(): void
    {
        try {
            Cache::increment('txn:stats:miss');
        } catch (\Throwable $e) {
        }
    }
}
