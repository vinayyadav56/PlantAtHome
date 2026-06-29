<?php

namespace Marvel\Translation;

use Marvel\Database\Models\TranslationProviderConfig;
use Marvel\Translation\Contracts\TranslationProvider;
use Marvel\Translation\Providers\AzureTranslationProvider;
use Marvel\Translation\Providers\ClaudeTranslationProvider;
use Marvel\Translation\Providers\DeepLTranslationProvider;
use Marvel\Translation\Providers\GoogleTranslateProvider;
use Marvel\Translation\Providers\OpenAiTranslationProvider;

/**
 * Resolves the ACTIVE translation provider from admin config (DB) with an env
 * fallback. Switching providers is therefore a setting change — no code change.
 * Tests can swap the whole manager via the container.
 */
class TranslationManager
{
    public const PROVIDERS = [
        'google' => GoogleTranslateProvider::class,
        'openai' => OpenAiTranslationProvider::class,
        'claude' => ClaudeTranslationProvider::class,
        'azure'  => AzureTranslationProvider::class,
        'deepl'  => DeepLTranslationProvider::class,
    ];

    /** Build the active provider (DB-configured, else config default). */
    public function active(): TranslationProvider
    {
        $row = null;
        try {
            $row = TranslationProviderConfig::where('is_active', true)->where('enabled', true)->first();
        } catch (\Throwable $e) {
            // table may not exist in some test contexts — fall back to config.
        }

        if ($row) {
            // `credentials` is decrypted by the cast; merge non-secret settings.
            $config = array_merge((array) $row->settings, (array) $row->credentials);
            return $this->make($row->provider, $config);
        }

        return $this->make(config('translation.default_provider', 'google'), []);
    }

    public function make(string $id, array $config = []): TranslationProvider
    {
        $class = self::PROVIDERS[$id] ?? GoogleTranslateProvider::class;
        return new $class($config);
    }

    /** Ids of all supported providers (for the admin dropdown). */
    public function available(): array
    {
        return array_keys(self::PROVIDERS);
    }
}
