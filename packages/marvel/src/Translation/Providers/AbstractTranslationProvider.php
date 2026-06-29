<?php

namespace Marvel\Translation\Providers;

use Marvel\Translation\Contracts\TranslationProvider;

abstract class AbstractTranslationProvider implements TranslationProvider
{
    /** @var array<string,mixed> decrypted credentials + non-secret settings */
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function estimateCost(int $characters): float
    {
        $rate = (float) (config('translation.cost_per_million_chars.' . $this->id(), 20.0));
        return round(($characters / 1_000_000) * $rate, 6);
    }

    /** Human-readable target-language name for LLM prompts. */
    protected function languageName(string $code): string
    {
        return config('translation.language_names.' . $code, $code);
    }

    protected function cfg(string $key, $default = null)
    {
        return $this->config[$key] ?? config('translation.providers.' . $this->id() . '.' . $key, $default);
    }
}
