<?php

namespace Marvel\Translation\Contracts;

/**
 * A pluggable translation backend. Implementations are never referenced directly
 * by application code — TranslationManager resolves the active one from admin
 * config, so switching providers is a setting change, not a code change.
 */
interface TranslationProvider
{
    /** Stable id: google | openai | claude | azure | deepl. */
    public function id(): string;

    /**
     * Translate a keyed batch of strings. The returned array MUST use the SAME
     * keys as the input so the caller can map results back. Implementations
     * should preserve placeholders/HTML and never translate brand names.
     *
     * @param  array<string,string> $strings  key => source text
     * @return array<string,string>           key => translated text
     */
    public function translateBatch(array $strings, string $targetLang, string $sourceLang = 'en'): array;

    /** Rough USD cost for $characters (drives the admin cost dashboard). */
    public function estimateCost(int $characters): float;
}
