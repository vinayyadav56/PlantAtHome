<?php

namespace Marvel\Translation\Providers;

use Marvel\Traits\AiTranslationTrait;

/**
 * Anthropic Claude provider — wraps the existing AiTranslationTrait (already
 * chunked, retried, placeholder/HTML/brand-safe, structured output). Highest
 * quality; pricier. No new HTTP code.
 */
class ClaudeTranslationProvider extends AbstractTranslationProvider
{
    use AiTranslationTrait;

    public function id(): string
    {
        return 'claude';
    }

    public function translateBatch(array $strings, string $targetLang, string $sourceLang = 'en'): array
    {
        if (empty($strings)) {
            return [];
        }
        $model = $this->cfg('model', 'claude-sonnet-4-6');
        // aiTranslateMap preserves keys + placeholders and returns the translated map.
        return $this->aiTranslateMap($strings, $this->languageName($targetLang), $model);
    }

    /** AiTranslationTrait reads ANTHROPIC_API_KEY by default; honour DB-config key. */
    protected function aiApiKey(): string
    {
        return (string) ($this->cfg('api_key') ?: env('ANTHROPIC_API_KEY', ''));
    }
}
