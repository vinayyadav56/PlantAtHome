<?php

namespace Marvel\Ai;

use Exception;
use Marvel\Ai\AiInterface;
use Marvel\Ai\Base;
use OpenAI as OpenAIClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Openai extends Base implements AiInterface
{
    private $openAiClient;

    public function __construct()
    {
        $this->openAiClient = OpenAIClient::client(config('shop.openai.secret_Key'));
        parent::__construct();
    }

    /** Chat model used for all text generation (overridable via env OPENAI_TEXT_MODEL). */
    private function textModel(): string
    {
        return config('shop.openai.text_model', 'gpt-4o-mini');
    }

    /** Map a length option (short|medium|long, or a character count) to a prompt hint. */
    private function lengthHint($length): string
    {
        if (is_numeric($length)) {
            $n = max(80, min(4000, (int) $length));
            return "Aim for roughly {$n} characters.";
        }
        return match ((string) $length) {
            'short' => 'Keep it short — a single tight paragraph (about 250-400 characters).',
            'long'  => 'Write a rich description — 2-3 short paragraphs plus a brief bulleted list of key features (about 800-1200 characters).',
            default => 'Medium length — 1-2 short paragraphs (about 450-700 characters).',
        };
    }

    private function maxTokensFor($length): int
    {
        if (is_numeric($length)) {
            return max(160, min(1500, (int) ceil(((int) $length) / 3) + 150));
        }
        return match ((string) $length) {
            'short' => 320,
            'long'  => 1100,
            default => 650,
        };
    }

    private function descriptionSystemPrompt(string $language, string $tone, $length): string
    {
        return "You write compelling, accurate e-commerce product descriptions for an online plant & gardening store. "
            . "Tone: {$tone}. " . $this->lengthHint($length) . " "
            . "Return clean, semantic HTML using ONLY <p>, <ul>, <li>, <strong> and <em> tags — no markdown, no headings, "
            . "no <html>/<body> wrapper, and no surrounding quotes. Do not invent specifications, prices, or guarantees. "
            . "Respond in the language with ISO 639 code '{$language}' (use English if you don't recognise it).";
    }

    /**
     * Single-field product description. Honours optional length / tone /
     * language / keywords carried on the request.
     */
    public function generateDescription($request): mixed
    {
        try {
            $language = $request->language ?? 'en';
            $tone     = $request->tone ?? 'professional';
            $length   = $request->length ?? 'medium';
            $keywords = $request->keywords ?? null;

            $user = (string) $request->prompt;
            if (!empty($keywords)) {
                $user .= "\n\nMake sure to naturally include these key points: {$keywords}";
            }

            $response = $this->openAiClient->chat()->create([
                'model'       => $this->textModel(),
                'max_tokens'  => $this->maxTokensFor($length),
                'temperature' => 0.7,
                'messages'    => [
                    ['role' => 'system', 'content' => $this->descriptionSystemPrompt($language, $tone, $length)],
                    ['role' => 'user',   'content' => $user],
                ],
            ]);

            $content = $response->choices[0]->message->content ?? '';
            return ['status' => 'success', 'result' => trim($content)];
        } catch (Exception $e) {
            throw new HttpException(400, SOMETHING_WENT_WRONG);
        }
    }

    /**
     * Map a product to the best-fitting EXISTING categories. Never invents new
     * ones — only returns rows from the provided candidate list.
     * $request->categories = [['id'=>, 'name'=>, 'slug'=>], ...]
     * Returns ['status'=>'success', 'result'=> matched candidate rows].
     */
    public function suggestCategories($request): mixed
    {
        try {
            $candidates = collect($request->categories ?? [])
                ->filter(fn ($c) => !empty($c['slug']) && !empty($c['name']))
                ->values();
            if ($candidates->isEmpty()) {
                return ['status' => 'success', 'result' => []];
            }

            $name        = (string) ($request->name ?? '');
            $description = trim(strip_tags((string) ($request->description ?? '')));
            $max         = (int) ($request->max ?? 5);

            $catList = $candidates->map(fn ($c) => "- {$c['slug']} :: {$c['name']}")->implode("\n");

            $system = "You are a taxonomist for an online plant & gardening store. "
                . "From the provided list of EXISTING categories, choose the {$max} (or fewer) that best fit the product. "
                . "Only choose slugs that appear in the list — never invent categories. "
                . "Respond ONLY with JSON in this exact shape: {\"category_slugs\": [\"slug-a\", \"slug-b\"]}.";
            $user = "Product name: {$name}\n"
                . ($description !== '' ? "Product description: {$description}\n" : '')
                . "\nExisting categories (slug :: name):\n{$catList}";

            $response = $this->openAiClient->chat()->create([
                'model'           => $this->textModel(),
                'max_tokens'      => 300,
                'temperature'     => 0.2,
                'response_format' => ['type' => 'json_object'],
                'messages'        => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
            ]);

            $content = $response->choices[0]->message->content ?? '{}';
            $parsed  = json_decode($content, true) ?: [];
            $slugs   = array_values(array_filter((array) ($parsed['category_slugs'] ?? []), 'is_string'));

            $bySlug  = $candidates->keyBy('slug');
            $matched = collect($slugs)
                ->map(fn ($s) => $bySlug->get($s))
                ->filter()
                ->unique('id')
                ->take($max)
                ->values()
                ->all();

            return ['status' => 'success', 'result' => $matched];
        } catch (Exception $e) {
            throw new HttpException(400, SOMETHING_WENT_WRONG);
        }
    }

    /**
     * Combined single-call generation for the bulk flow: returns a description
     * and/or matching category ids in ONE request (cost-efficient). Errors bubble
     * up so the batch job can record them per-row.
     * Returns ['description'=>?html, 'category_ids'=>[], 'category_slugs'=>[]].
     */
    public function generateProductContent($request): mixed
    {
        $language = $request->language ?? 'en';
        $tone     = $request->tone ?? 'professional';
        $length   = $request->length ?? 'medium';
        $wantDesc = $request->want_description ?? true;
        $wantCats = $request->want_categories ?? true;

        $candidates = collect($request->categories ?? [])
            ->filter(fn ($c) => !empty($c['slug']) && !empty($c['name']))
            ->values();
        $catList = $candidates->map(fn ($c) => "- {$c['slug']} :: {$c['name']}")->implode("\n");

        $parts = [];
        if ($wantDesc) {
            $parts[] = "\"description_html\": a product description as clean HTML (only <p>,<ul>,<li>,<strong>,<em>). " . $this->lengthHint($length);
        }
        if ($wantCats && $candidates->isNotEmpty()) {
            $parts[] = "\"category_slugs\": an array of 1-5 slugs chosen ONLY from the provided category list (never invent).";
        }
        $shape = implode(', ', $parts);

        $system = "You are an expert copywriter and taxonomist for an online plant & gardening store. "
            . "Tone: {$tone}. Do not invent specs, prices or guarantees. "
            . "Write any description text in the language with ISO 639 code '{$language}' (English if unknown). "
            . "Respond ONLY with a JSON object containing: {$shape}.";
        $user = "Product name: {$request->name}\n"
            . (!empty($request->prompt) ? "Extra context: {$request->prompt}\n" : '')
            . ($wantCats && $candidates->isNotEmpty() ? "\nExisting categories (slug :: name):\n{$catList}\n" : '');

        $response = $this->openAiClient->chat()->create([
            'model'           => $this->textModel(),
            'max_tokens'      => $this->maxTokensFor($length) + 120,
            'temperature'     => 0.6,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
        ]);

        $content = $response->choices[0]->message->content ?? '{}';
        $parsed  = json_decode($content, true) ?: [];

        $description = $wantDesc ? trim((string) ($parsed['description_html'] ?? '')) : null;

        $slugs = [];
        $ids   = [];
        if ($wantCats) {
            $slugs  = array_values(array_filter((array) ($parsed['category_slugs'] ?? []), 'is_string'));
            $bySlug = $candidates->keyBy('slug');
            $ids    = collect($slugs)->map(fn ($s) => optional($bySlug->get($s))['id'])->filter()->unique()->values()->all();
        }

        return [
            'description'    => $description,
            'category_ids'   => $ids,
            'category_slugs' => $slugs,
        ];
    }
}
