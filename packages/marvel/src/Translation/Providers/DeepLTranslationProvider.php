<?php

namespace Marvel\Translation\Providers;

use Illuminate\Support\Facades\Http;
use Marvel\Exceptions\MarvelException;

/**
 * DeepL provider. Best-in-class for European languages; weaker on Indian
 * languages — included for completeness/provider-parity.
 */
class DeepLTranslationProvider extends AbstractTranslationProvider
{
    public function id(): string
    {
        return 'deepl';
    }

    public function translateBatch(array $strings, string $targetLang, string $sourceLang = 'en'): array
    {
        if (empty($strings)) {
            return [];
        }
        $key = $this->cfg('api_key');
        if (!$key) {
            throw new MarvelException('DeepL API key not configured.');
        }
        $endpoint = $this->cfg('endpoint', 'https://api-free.deepl.com/v2/translate');

        $keys = array_keys($strings);
        $response = Http::asForm()
            ->withHeaders(['Authorization' => 'DeepL-Auth-Key ' . $key])
            ->timeout(30)
            ->retry(3, 1500, throw: false)
            ->post($endpoint, [
                'text' => array_values($strings),
                'target_lang' => strtoupper($targetLang),
                'source_lang' => strtoupper($sourceLang),
            ]);

        if (!$response->successful()) {
            throw new MarvelException('DeepL error: ' . $response->status() . ' ' . $response->body());
        }

        $rows = $response->json('translations', []);
        $out = [];
        foreach ($keys as $i => $key2) {
            $out[$key2] = $rows[$i]['text'] ?? $strings[$key2];
        }
        return $out;
    }
}
