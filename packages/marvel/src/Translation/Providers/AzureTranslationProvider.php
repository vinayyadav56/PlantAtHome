<?php

namespace Marvel\Translation\Providers;

use Illuminate\Support\Facades\Http;
use Marvel\Exceptions\MarvelException;

/**
 * Azure AI Translator (Text API v3.0). Good Indian-language coverage, low cost.
 */
class AzureTranslationProvider extends AbstractTranslationProvider
{
    public function id(): string
    {
        return 'azure';
    }

    public function translateBatch(array $strings, string $targetLang, string $sourceLang = 'en'): array
    {
        if (empty($strings)) {
            return [];
        }
        $key = $this->cfg('api_key');
        if (!$key) {
            throw new MarvelException('Azure Translator key not configured.');
        }
        $endpoint = rtrim($this->cfg('endpoint', 'https://api.cognitive.microsofttranslator.com'), '/');
        $region = $this->cfg('region');

        $keys = array_keys($strings);
        $body = array_map(fn($v) => ['Text' => (string) $v], array_values($strings));

        $req = Http::withHeaders(array_filter([
            'Ocp-Apim-Subscription-Key' => $key,
            'Ocp-Apim-Subscription-Region' => $region,
            'Content-Type' => 'application/json',
        ]))->timeout(30)->retry(3, 1500, throw: false);

        $response = $req->post($endpoint . '/translate?api-version=3.0&from=' . $sourceLang . '&to=' . $targetLang, $body);

        if (!$response->successful()) {
            throw new MarvelException('Azure Translator error: ' . $response->status() . ' ' . $response->body());
        }

        $rows = $response->json();
        $out = [];
        foreach ($keys as $i => $key2) {
            $out[$key2] = $rows[$i]['translations'][0]['text'] ?? $strings[$key2];
        }
        return $out;
    }
}
