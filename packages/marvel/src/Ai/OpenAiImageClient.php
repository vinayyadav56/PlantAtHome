<?php

namespace Marvel\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin OpenAI Images client over Laravel's HTTP client.
 *
 * Deliberately NOT the installed openai-php SDK: that pin (v0.7, mid-2023)
 * predates gpt-image-1 — its images.edit path is single-image DALL·E-2 shaped
 * and can't carry model/quality/background or multiple reference images.
 * Direct HTTP gives full param control and tests fake it with Http::fake().
 *
 * Both calls return ['bytes' => rawPngBytes, 'revised_prompt' => ?string].
 */
class OpenAiImageClient
{
    private const BASE = 'https://api.openai.com/v1';

    /** Text → image (no reference images). */
    public function generate(
        string $model,
        string $prompt,
        string $size,
        ?string $quality = null,
        ?string $style = null,
        ?string $background = null,
    ): array {
        $payload = [
            'model'  => $model,
            'prompt' => $prompt,
            'n'      => 1,
            'size'   => $size,
        ];

        if ($model === 'gpt-image-1') {
            // gpt-image-1 always returns b64_json; response_format is not a param.
            if ($quality) {
                $payload['quality'] = $quality;
            }
            if ($background) {
                $payload['background'] = $background;
            }
        } else {
            // dall-e-3 family: hosted-URL default — ask for b64 so storage is one hop.
            $payload['response_format'] = 'b64_json';
            if ($quality) {
                $payload['quality'] = $quality;
            }
            if ($style) {
                $payload['style'] = $style;
            }
        }

        $response = $this->request()->post(self::BASE . '/images/generations', $payload);

        return $this->parse($response);
    }

    /**
     * Reference-guided generation (gpt-image-1 images.edit).
     *
     * @param array<int, array{path: string, name: string}> $referenceFiles local temp files
     */
    public function edit(
        string $model,
        string $prompt,
        string $size,
        ?string $quality,
        ?string $background,
        array $referenceFiles,
    ): array {
        $request = $this->request()->asMultipart();

        foreach ($referenceFiles as $file) {
            $handle = fopen($file['path'], 'r');
            if ($handle === false) {
                throw new ImageGenerationException("Could not read reference image {$file['name']}.");
            }
            // image[] — gpt-image-1 accepts multiple reference images.
            $request = $request->attach('image[]', $handle, $file['name']);
        }

        $fields = [
            'model'  => $model,
            'prompt' => $prompt,
            'n'      => '1',
            'size'   => $size,
        ];
        if ($quality) {
            $fields['quality'] = $quality;
        }
        if ($background) {
            $fields['background'] = $background;
        }

        $response = $request->post(self::BASE . '/images/edits', $fields);

        return $this->parse($response);
    }

    private function request(): PendingRequest
    {
        $key = config('shop.openai.secret_Key');
        if (!$key) {
            throw new ImageGenerationException('OPENAI_SECRET_KEY is not configured.');
        }

        return Http::withToken($key)
            ->timeout((int) config('image-batches.request_timeout', 300))
            ->connectTimeout(30);
    }

    private function parse(Response $response): array
    {
        if ($response->failed()) {
            $status  = $response->status();
            $message = $response->json('error.message')
                ?? ('OpenAI image request failed with HTTP ' . $status);

            throw new ImageGenerationException(
                mb_substr((string) $message, 0, 500),
                $status,
                retryable: $status === 429 || $status >= 500,
            );
        }

        $b64 = $response->json('data.0.b64_json');
        if (!$b64) {
            throw new ImageGenerationException('OpenAI response contained no image data.', $response->status());
        }

        $bytes = base64_decode((string) $b64, true);
        if ($bytes === false || $bytes === '') {
            throw new ImageGenerationException('OpenAI returned undecodable image data.', $response->status());
        }

        return [
            'bytes'          => $bytes,
            'revised_prompt' => $response->json('data.0.revised_prompt'),
        ];
    }
}
