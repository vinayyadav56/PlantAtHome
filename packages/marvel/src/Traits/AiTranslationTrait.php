<?php

namespace Marvel\Traits;

use Illuminate\Support\Facades\Http;

/**
 * Shared Claude-backed translation helper for the localization commands (lang files + catalog).
 * Uses raw HTTP (no extra composer dependency) against the Anthropic Messages API with structured
 * output so the response is guaranteed same-keys JSON. Preserves placeholders + the brand name.
 */
trait AiTranslationTrait
{
    /** Path separator for flatten/unflatten (a control char so real keys are preserved). */
    private function trSep(): string
    {
        return "\x01";
    }

    protected function aiApiKey(): string
    {
        // Through config, not env(), so an Anthropic key managed in Settings → Integrations
        // overlays it. config/services.php still defaults the key to ANTHROPIC_API_KEY.
        $key = (string) (config('services.anthropic.api_key') ?: '');
        if ($key === '') {
            throw new \RuntimeException('No Anthropic API key: set ANTHROPIC_API_KEY or the Anthropic provider in Settings → Integrations.');
        }
        return $key;
    }

    /** Flatten a nested assoc array to dot-free leaf paths joined by a control char. */
    protected function flattenMap(array $arr, string $prefix = '', array &$out = []): array
    {
        foreach ($arr as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . $this->trSep() . $k;
            if (is_array($v)) {
                $this->flattenMap($v, $key, $out);
            } elseif (is_string($v)) {
                $out[$key] = $v;
            }
        }
        return $out;
    }

    protected function unflattenMap(array $flat): array
    {
        $out = [];
        foreach ($flat as $key => $v) {
            $parts = explode($this->trSep(), $key);
            $node = &$out;
            foreach ($parts as $i => $p) {
                if ($i === count($parts) - 1) {
                    $node[$p] = $v;
                } else {
                    if (!isset($node[$p]) || !is_array($node[$p])) {
                        $node[$p] = [];
                    }
                    $node = &$node[$p];
                }
            }
            unset($node);
        }
        return $out;
    }

    /**
     * Translate the VALUES of a flat string map into $languageName (chunked). Returns same keys.
     */
    protected function aiTranslateMap(array $flatMap, string $languageName, string $model = 'claude-sonnet-4-6', int $chunkSize = 60): array
    {
        $key = $this->aiApiKey();
        $result = [];
        foreach (array_chunk($flatMap, $chunkSize, true) as $chunk) {
            $result += $this->aiTranslateChunk($chunk, $languageName, $model, $key);
        }
        return $result;
    }

    private function aiTranslateChunk(array $chunk, string $languageName, string $model, string $key): array
    {
        $system = "You are a professional {$languageName} localizer for an Indian plant e-commerce platform (PlantAtHome). "
            . "Translate the VALUES of the given JSON into natural, idiomatic {$languageName}. "
            . "Return ONLY a JSON object with the SAME keys. Never translate keys. "
            . "Preserve EXACTLY all placeholders (:name, {count}, {{var}}, %s, %d), HTML tags, URLs and the brand 'PlantAtHome'. "
            . "If a value is empty or purely a placeholder/number, return it unchanged. Keep it concise.";

        $schemaProps = [];
        foreach ($chunk as $k => $_) {
            $schemaProps[(string) $k] = ['type' => 'string'];
        }

        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                $resp = Http::withHeaders([
                    'x-api-key' => $key,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => 8000,
                    'system' => $system,
                    'messages' => [[
                        'role' => 'user',
                        'content' => 'Translate this JSON:' . "\n" . json_encode($chunk, JSON_UNESCAPED_UNICODE),
                    ]],
                    'output_config' => ['format' => [
                        'type' => 'json_schema',
                        'schema' => [
                            'type' => 'object',
                            'properties' => $schemaProps,
                            'required' => array_keys($schemaProps),
                            'additionalProperties' => false,
                        ],
                    ]],
                ]);

                if ($resp->status() === 429 || $resp->status() >= 500) {
                    throw new \RuntimeException('retryable ' . $resp->status());
                }
                if (!$resp->successful()) {
                    throw new \RuntimeException('API ' . $resp->status() . ': ' . substr($resp->body(), 0, 200));
                }
                $text = '';
                foreach ((array) $resp->json('content') as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $text = $block['text'];
                        break;
                    }
                }
                $decoded = json_decode($text, true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('non-JSON response');
                }
                return $decoded;
            } catch (\Throwable $e) {
                if ($attempt === 3) {
                    throw $e;
                }
                usleep((int) (1500000 * (2 ** $attempt)));
            }
        }
        return [];
    }
}
