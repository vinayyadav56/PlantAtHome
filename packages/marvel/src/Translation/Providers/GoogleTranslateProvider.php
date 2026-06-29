<?php

namespace Marvel\Translation\Providers;

use Illuminate\Support\Facades\Http;
use Marvel\Exceptions\MarvelException;

/**
 * Google Cloud Translation (v2 REST, api-key). Cheapest + fastest at catalog
 * scale and strong on Indian languages — the platform default.
 */
class GoogleTranslateProvider extends AbstractTranslationProvider
{
    public function id(): string
    {
        return 'google';
    }

    public function translateBatch(array $strings, string $targetLang, string $sourceLang = 'en'): array
    {
        if (empty($strings)) {
            return [];
        }
        $apiKey = $this->cfg('api_key');
        if (!$apiKey) {
            throw new MarvelException('Google Translate API key not configured.');
        }
        $endpoint = $this->cfg('endpoint', 'https://translation.googleapis.com/language/translate/v2');

        // Preserve key→value association by translating values in a stable order.
        $keys = array_keys($strings);
        $values = array_values($strings);

        $response = Http::asForm()
            ->timeout(30)
            ->retry(3, 1500, throw: false)
            ->post($endpoint . '?key=' . urlencode($apiKey), [
                'q'      => $values,        // multiple q's = one batched call
                'source' => $sourceLang,
                'target' => $targetLang,
                'format' => 'text',
            ]);

        if (!$response->successful()) {
            throw new MarvelException('Google Translate error: ' . $response->status() . ' ' . $response->body());
        }

        $translations = $response->json('data.translations', []);
        $out = [];
        foreach ($keys as $i => $key) {
            $out[$key] = html_entity_decode(
                $translations[$i]['translatedText'] ?? $values[$i],
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
        }
        return $out;
    }
}
