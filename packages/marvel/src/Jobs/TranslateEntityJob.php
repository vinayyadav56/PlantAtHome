<?php

namespace Marvel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\TranslationCache;
use Marvel\Translation\TranslationService;

/**
 * Translate ONE entity into ONE language, idempotently. Dispatched lazily by the
 * read-overlay on a cache miss and by the observer when English content changes.
 * Runs on a dedicated `translations` queue so it never blocks order/payment work.
 */
class TranslateEntityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** Response-cache groups to bust when a translation lands. */
    protected const CACHE_GROUPS = [
        'Product'  => 'products',
        'Category' => 'categories',
        'Type'     => 'types',
        'Tag'      => 'tags',
        'Shop'     => 'shops',
    ];

    public function __construct(
        public string $type,
        public int $id,
        public string $language
    ) {
    }

    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function middleware(): array
    {
        // Collapse concurrent jobs for the same (entity, language).
        return [(new WithoutOverlapping("txnjob:{$this->type}:{$this->id}:{$this->language}"))->dontRelease()];
    }

    public function handle(TranslationService $service): void
    {
        if (!class_exists($this->type)) {
            return;
        }
        /** @var \Illuminate\Database\Eloquent\Model|null $model */
        $model = $this->type::find($this->id); // overlay inactive in worker → canonical English
        if (!$model || !method_exists($model, 'getTranslatableSource')) {
            return;
        }

        $source = $model->getTranslatableSource();
        $sourceHash = $model->translatableSourceHash();

        // Idempotent: a fresh translation for the current source already exists.
        $existing = TranslationCache::where('translatable_type', $this->type)
            ->where('translatable_id', $this->id)
            ->where('language', $this->language)
            ->first();
        if ($existing && $existing->status === TranslationCache::STATUS_TRANSLATED && $existing->source_hash === $sourceHash) {
            return;
        }

        try {
            $result = $service->translateFields($source, $this->language);
        } catch (\Throwable $e) {
            Log::warning('TranslateEntityJob failed', ['type' => $this->type, 'id' => $this->id, 'lang' => $this->language, 'error' => $e->getMessage()]);
            TranslationCache::updateOrCreate(
                ['translatable_type' => $this->type, 'translatable_id' => $this->id, 'language' => $this->language],
                ['status' => TranslationCache::STATUS_FAILED, 'last_error' => mb_substr($e->getMessage(), 0, 500), 'source_hash' => $sourceHash]
            );
            throw $e; // let the queue retry with backoff
        }

        $version = $existing ? ((int) $existing->version + 1) : 1;
        TranslationCache::updateOrCreate(
            ['translatable_type' => $this->type, 'translatable_id' => $this->id, 'language' => $this->language],
            [
                'translated_fields'  => $result['fields'],
                'source_hash'        => $sourceHash,
                'translation_source' => $result['provider'],
                'status'             => TranslationCache::STATUS_TRANSLATED,
                'version'            => $version,
                'last_error'         => null,
                // preserve a human's review flag across re-translations.
                'is_reviewed'        => $existing->is_reviewed ?? false,
            ]
        );

        // Write-through Redis + bust the per-language response cache.
        $this->refreshCaches($result['fields']);
    }

    protected function refreshCaches(array $fields): void
    {
        $prefix = config('translation.cache_prefix', 'txn');
        $short = strtolower(class_basename($this->type));
        try {
            Cache::forever("{$prefix}:{$short}:{$this->id}:{$this->language}", $fields);
        } catch (\Throwable $e) {
        }

        $group = self::CACHE_GROUPS[class_basename($this->type)] ?? ($short . 's');
        try {
            Cache::forever("{$group}:ver", ((int) Cache::get("{$group}:ver", 1)) + 1);
        } catch (\Throwable $e) {
        }
    }
}
