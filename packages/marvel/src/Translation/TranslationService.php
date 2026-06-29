<?php

namespace Marvel\Translation;

use Marvel\Database\Models\TranslationStringCache;

/**
 * Orchestrates dynamic-content translation with aggressive cost control:
 *   - dedupes identical source strings via translation_string_cache (translate
 *     each distinct phrase ONCE per language, reuse forever),
 *   - batches the remaining misses into a single provider call.
 *
 * Returns the translated field map; persistence to translations_cache + Redis is
 * the caller's (TranslateEntityJob's) job.
 */
class TranslationService
{
    public function __construct(protected TranslationManager $manager)
    {
    }

    /**
     * Translate a [field => englishText] map into $lang.
     *
     * @return array{fields: array<string,string>, provider: string}
     */
    public function translateFields(array $source, string $lang): array
    {
        $provider = $this->manager->active();
        $providerId = $provider->id();
        $sourceLang = config('translation.default_language', 'en');

        // Distinct, non-empty source values (dedupe within the entity too).
        $values = [];
        foreach ($source as $v) {
            $v = (string) $v;
            if ($v !== '') {
                $values[$v] = true;
            }
        }
        $values = array_keys($values);

        // 1) string-cache lookup.
        $valueToTranslated = [];
        $misses = []; // hash => value
        foreach ($values as $v) {
            $hash = TranslationStringCache::hashFor($lang, $v);
            $cached = TranslationStringCache::where('source_hash', $hash)->value('translated_text');
            if ($cached !== null) {
                $valueToTranslated[$v] = $cached;
            } else {
                $misses[$hash] = $v;
            }
        }

        // 2) batch-translate the misses in ONE provider call.
        if (!empty($misses)) {
            $translated = $provider->translateBatch($misses, $lang, $sourceLang); // hash => translated
            foreach ($misses as $hash => $v) {
                $t = $translated[$hash] ?? $v;
                $valueToTranslated[$v] = $t;
                // persist for permanent reuse.
                TranslationStringCache::updateOrCreate(
                    ['source_hash' => $hash],
                    ['language' => $lang, 'source_text' => $v, 'translated_text' => $t, 'provider' => $providerId]
                );
            }
        }

        // 3) map fields back.
        $fields = [];
        foreach ($source as $field => $v) {
            $v = (string) $v;
            $fields[$field] = $v === '' ? $v : ($valueToTranslated[$v] ?? $v);
        }

        return ['fields' => $fields, 'provider' => $providerId];
    }
}
