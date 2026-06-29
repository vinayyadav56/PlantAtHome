<?php

namespace Marvel\Translation\Providers;

use Illuminate\Support\Facades\Http;
use Marvel\Exceptions\MarvelException;

/**
 * OpenAI chat-completion provider (JSON-in / JSON-out). Mid cost/quality;
 * already used elsewhere for product-description generation.
 */
class OpenAiTranslationProvider extends AbstractTranslationProvider
{
    public function id(): string
    {
        return 'openai';
    }

    public function translateBatch(array $strings, string $targetLang, string $sourceLang = 'en'): array
    {
        if (empty($strings)) {
            return [];
        }
        $apiKey = $this->cfg('api_key') ?: config('shop.openai.secret_Key');
        if (!$apiKey) {
            throw new MarvelException('OpenAI API key not configured.');
        }
        $model = $this->cfg('model', 'gpt-4o-mini');
        $langName = $this->languageName($targetLang);

        $system = "You are a professional e-commerce localizer. Translate each JSON string VALUE from {$sourceLang} to {$langName}. "
            . "Return ONLY a JSON object with the SAME keys. Preserve placeholders ({{x}}, :name, %s), HTML tags, URLs, numbers and the brand name 'PlantAtHome'. Do not add commentary.";

        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->retry(3, 2000, throw: false)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => json_encode($strings, JSON_UNESCAPED_UNICODE)],
                ],
            ]);

        if (!$response->successful()) {
            throw new MarvelException('OpenAI error: ' . $response->status() . ' ' . $response->body());
        }

        $content = $response->json('choices.0.message.content', '{}');
        $decoded = json_decode($content, true) ?: [];

        // Keep input keys; fall back to source on any missing key.
        $out = [];
        foreach ($strings as $key => $src) {
            $out[$key] = is_string($decoded[$key] ?? null) ? $decoded[$key] : $src;
        }
        return $out;
    }
}
