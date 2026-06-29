<?php

namespace Marvel\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\TranslationCache;
use Marvel\Jobs\TranslateEntityJob;

/**
 * Versioning: when an entity's English (canonical) translatable fields change, its
 * existing translations become stale. Mark them `outdated` (so the overlay stops
 * serving them — "never serve stale translations"), bust their Redis keys, and
 * requeue a fresh translation for each language that previously had one.
 *
 * Attached only to translatable models, so every observed model exposes the
 * HasTranslationOverlay helpers.
 */
class TranslatableObserver
{
    public function saved(Model $model): void
    {
        if (!method_exists($model, 'getTranslatableFields')) {
            return;
        }
        $fields = $model->getTranslatableFields();
        // Only react when a translatable field actually changed (ignore price/stock saves).
        if (empty($fields) || !$model->wasChanged($fields)) {
            return;
        }

        $type = get_class($model);
        $id = (int) $model->getKey();
        $newHash = $model->translatableSourceHash();

        // Rows that no longer match the source become outdated.
        $rows = TranslationCache::where('translatable_type', $type)
            ->where('translatable_id', $id)
            ->get(['id', 'language', 'source_hash']);

        if ($rows->isEmpty()) {
            return; // nothing translated yet — overlay will lazily enqueue on demand.
        }

        $prefix = config('translation.cache_prefix', 'txn');
        $short = strtolower(class_basename($type));
        $queue = config('translation.queue', 'translations');

        foreach ($rows as $row) {
            if ($row->source_hash === $newHash) {
                continue; // already current
            }
            TranslationCache::whereKey($row->id)->update(['status' => TranslationCache::STATUS_OUTDATED]);
            try {
                Cache::forget("{$prefix}:{$short}:{$id}:{$row->language}");
            } catch (\Throwable $e) {
            }
            // Requeue retranslation for the language that had a translation.
            TranslateEntityJob::dispatch($type, $id, $row->language)->onQueue($queue);
        }
    }
}
