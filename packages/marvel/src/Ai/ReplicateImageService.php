<?php

namespace Marvel\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Replicate (Flux) image client — the SECOND provider behind the admin
 * instant image generator (OpenAI gpt-image-1 stays the default).
 *
 * Mirrors OpenAiImageClient's contract exactly so GenerateInstantImageJob can
 * treat both providers identically: generate() returns
 * ['bytes' => rawImageBytes, 'revised_prompt' => ?string] and every failure
 * (HTTP error, failed/canceled prediction, poll timeout, undownloadable
 * output) throws ImageGenerationException.
 *
 * Flow: POST /v1/models/{model}/predictions with `Prefer: wait` (Replicate
 * holds the connection up to ~60s). If the prediction is still
 * starting/processing when the wait elapses, poll GET /v1/predictions/{id}
 * every few seconds up to POLL_TIMEOUT_SECONDS.
 *
 * SECURITY: the API token is only ever placed in the Authorization header —
 * never logged, never thrown in an exception message. (Request logging is
 * safe regardless: LogRequests redacts `api_token`/`authorization` keys and
 * never persists outbound request headers.)
 */
class ReplicateImageService
{
    /** Aspect ratios the admin UI may offer for Flux (also the validation list). */
    public const ASPECT_RATIOS = ['1:1', '3:4', '4:3', '16:9', '9:16'];

    private const POLL_INTERVAL_SECONDS = 3;
    private const POLL_TIMEOUT_SECONDS  = 120;
    private const TERMINAL_STATUSES     = ['succeeded', 'failed', 'canceled'];

    /**
     * Text → image.
     *
     * @param array{aspect_ratio?: ?string, guidance?: mixed, num_inference_steps?: mixed, seed?: mixed} $opts
     * @return array{bytes: string, revised_prompt: ?string}
     */
    public function generate(string $prompt, array $opts = []): array
    {
        $base  = rtrim((string) config('services.replicate.base_url', 'https://api.replicate.com'), '/');
        $model = (string) config('services.replicate.model', 'black-forest-labs/flux-dev');

        $input = [
            'prompt'         => $prompt,
            'aspect_ratio'   => (string) (($opts['aspect_ratio'] ?? null) ?: '1:1'),
            'num_outputs'    => 1,
            'output_format'  => 'png',
            'output_quality' => 100,
        ];
        if (isset($opts['guidance']) && $opts['guidance'] !== '' && $opts['guidance'] !== null) {
            $input['guidance'] = (float) $opts['guidance'];
        }
        if (!empty($opts['num_inference_steps'])) {
            $input['num_inference_steps'] = (int) $opts['num_inference_steps'];
        }
        if (isset($opts['seed']) && $opts['seed'] !== '' && $opts['seed'] !== null) {
            $input['seed'] = (int) $opts['seed'];
        }

        // Prefer: wait — Replicate blocks up to ~60s and usually returns the
        // finished prediction in one round trip.
        $response = $this->request()
            ->withHeaders(['Prefer' => 'wait'])
            ->post("{$base}/v1/models/{$model}/predictions", ['input' => $input]);

        if ($response->failed()) {
            $this->throwHttpError($response);
        }

        $prediction = $this->waitForTerminal($base, (array) $response->json());

        $status = (string) ($prediction['status'] ?? 'unknown');
        if ($status !== 'succeeded') {
            $detail = $this->errorString($prediction) ?? "prediction ended with status '{$status}'";

            throw new ImageGenerationException(mb_substr('Replicate: ' . $detail, 0, 500));
        }

        $urls = $this->outputUrls($prediction['output'] ?? null);
        if (empty($urls)) {
            throw new ImageGenerationException('Replicate prediction succeeded but returned no output images.');
        }

        // num_outputs is pinned to 1 — download the single output. (If a model
        // ever returns more, the first is the image; extras are variants.)
        return [
            'bytes'          => $this->download($urls[0]),
            'revised_prompt' => null, // Flux does not rewrite prompts
        ];
    }

    /* ── internals ────────────────────────────────────────────────────────── */

    /** Poll GET /v1/predictions/{id} until terminal or POLL_TIMEOUT_SECONDS. */
    private function waitForTerminal(string $base, array $prediction): array
    {
        $started = time();
        while (
            !in_array((string) ($prediction['status'] ?? ''), self::TERMINAL_STATUSES, true)
        ) {
            $id = (string) ($prediction['id'] ?? '');
            if ($id === '') {
                throw new ImageGenerationException('Replicate response contained no prediction id to poll.');
            }
            if ((time() - $started) >= self::POLL_TIMEOUT_SECONDS) {
                throw new ImageGenerationException(
                    'Replicate generation timed out after ' . self::POLL_TIMEOUT_SECONDS . 's (still ' . ($prediction['status'] ?? 'unknown') . ').',
                    0,
                    retryable: true,
                );
            }

            sleep(self::POLL_INTERVAL_SECONDS);

            $response = $this->request()->get("{$base}/v1/predictions/{$id}");
            if ($response->failed()) {
                $this->throwHttpError($response);
            }
            $prediction = (array) $response->json();
        }

        return $prediction;
    }

    /** @return string[] output can be a single URL string or an array of URL strings */
    private function outputUrls($output): array
    {
        if (is_string($output) && $output !== '') {
            return [$output];
        }
        if (is_array($output)) {
            return array_values(array_filter($output, fn ($u) => is_string($u) && $u !== ''));
        }

        return [];
    }

    private function download(string $url): string
    {
        $response = Http::timeout(60)->connectTimeout(30)->get($url);
        if ($response->failed()) {
            throw new ImageGenerationException(
                'Could not download Replicate output image (HTTP ' . $response->status() . ').',
                $response->status(),
                retryable: $response->status() >= 500,
            );
        }

        $bytes = $response->body();
        if ($bytes === '') {
            throw new ImageGenerationException('Replicate output image was empty.');
        }

        return $bytes;
    }

    private function request(): PendingRequest
    {
        $token = config('services.replicate.api_token');
        if (!$token) {
            throw new ImageGenerationException('REPLICATE_API_TOKEN is not configured.');
        }

        return Http::withToken($token)
            ->acceptJson()
            ->timeout(90) // Prefer: wait can hold the connection ~60s
            ->connectTimeout(30);
    }

    /** Mirror OpenAiImageClient::parse's error convention (Replicate errors use `detail`). */
    private function throwHttpError(Response $response): never
    {
        $status  = $response->status();
        $message = $response->json('detail')
            ?? $this->errorString((array) $response->json())
            ?? ('Replicate request failed with HTTP ' . $status);

        throw new ImageGenerationException(
            mb_substr((string) $message, 0, 500),
            $status,
            retryable: $status === 429 || $status >= 500,
        );
    }

    /** Replicate's `error` field may be a string or a structure — normalize to a string. */
    private function errorString(array $payload): ?string
    {
        $error = $payload['error'] ?? null;
        if (is_string($error) && $error !== '') {
            return $error;
        }
        if (is_array($error) && !empty($error)) {
            return (string) json_encode($error, JSON_UNESCAPED_SLASHES);
        }

        return null;
    }
}
