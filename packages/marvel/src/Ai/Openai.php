<?php

namespace Marvel\Ai;

use Exception;
use GuzzleHttp\Client as GuzzleClient;
use Marvel\Ai\AiInterface;
use Marvel\Ai\Base;
use OpenAI as OpenAIClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Openai extends Base implements AiInterface
{
    private $openAiClient;

    public function __construct()
    {
        // 90s HTTP timeout: a hung request fails (and the bulk row retries)
        // well inside the content queue job's time budget.
        $this->openAiClient = OpenAIClient::factory()
            ->withApiKey(config('shop.openai.secret_Key'))
            ->withHttpClient(new GuzzleClient(['timeout' => 90, 'connect_timeout' => 10]))
            ->make();
        parent::__construct();
    }

    /** Chat model used for all text generation (env OPENAI_TEXT_MODEL, via content-batches config). */
    private function textModel(): string
    {
        return config('content-batches.text_model', 'gpt-5.4-mini');
    }

    /**
     * Model, length cap and temperature for a text request. GPT-5 models are reasoning models:
     * they reject `max_tokens` and any temperature but the default, so they get
     * max_completion_tokens (with room for reasoning) and reasoning turned down to its lowest,
     * 'none' from GPT-5.1 on, 'minimal' for the first GPT-5 models.
     */
    private function textParams(int $maxTokens, float $temperature): array
    {
        $model = $this->textModel();
        if (!str_starts_with($model, 'gpt-5')) {
            return ['model' => $model, 'max_tokens' => $maxTokens, 'temperature' => $temperature];
        }

        return [
            'model'                 => $model,
            'max_completion_tokens' => $maxTokens + 1000,
            'reasoning_effort'      => preg_match('/^gpt-5(?:-mini|-nano)?(?:-\d{4}-\d{2}-\d{2})?$/', $model) ? 'minimal' : 'none',
        ];
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

            $response = $this->openAiClient->chat()->create(array_merge($this->textParams($this->maxTokensFor($length), 0.7), [
                'messages'    => [
                    ['role' => 'system', 'content' => $this->descriptionSystemPrompt($language, $tone, $length)],
                    ['role' => 'user',   'content' => $user],
                ],
            ]));

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

            $response = $this->openAiClient->chat()->create(array_merge($this->textParams(300, 0.2), [
                'response_format' => ['type' => 'json_object'],
                'messages'        => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
            ]));

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

        $response = $this->openAiClient->chat()->create(array_merge($this->textParams($this->maxTokensFor($length) + 120, 0.6), [
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
        ]));

        $content = $response->choices[0]->message->content ?? '';
        $parsed  = json_decode($content, true);
        // Truncated/invalid JSON (e.g. hit max_tokens) must surface as an error so
        // the batch row retries — never silently drop the result and mark it done.
        if (!is_array($parsed)) {
            throw new \RuntimeException('AI content response was not valid JSON (possibly truncated).');
        }

        // Guard the type: a stray array/object for description_html would stringify
        // to "Array" and get written as the live product description.
        $description = null;
        if ($wantDesc) {
            $d = $parsed['description_html'] ?? null;
            $description = is_string($d) ? trim($d) : null;
        }

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

    /**
     * "Generate missing content" for ONE plant: asks only for the requested
     * fields (JSON mode) and returns them raw — MissingPlantContent::validate()
     * decides what is safe to store. Errors bubble so the batch job can
     * classify them; a non-JSON or cut-off reply is \UnexpectedValueException
     * (invalid output, never retried).
     * $request = {name, fields: string[], context: array, vocabulary: {sunlight: {value: meaning}, water_requirement: {...}}}
     */
    public function generatePlantContent($request): mixed
    {
        $vocabulary = (array) ($request->vocabulary ?? []);
        $context    = (array) ($request->context ?? []);
        $choices    = fn (string $field) => implode('; ', array_map(
            fn ($value, $meaning) => "\"{$value}\" ({$meaning})",
            array_keys((array) ($vocabulary[$field] ?? [])),
            (array) ($vocabulary[$field] ?? [])
        ));

        $spec = [
            'description'       => '"description": a product description as clean HTML using only <p>, <ul>, <li>, <strong> and <em> (no headings), 450-900 visible characters: what the plant looks like, where it grows best and the basics of care. Use metric units (cm, not inches). Avoid clichés such as "stunning" or "must-have". Do not list light/water/humidity as bullet points: the page already shows a care card.',
            'seo_title'         => '"seo_title": a plain-text page title of at most 46 characters for a page that SELLS the plant (e.g. "Buy Areca Palm Plant Online" or "Areca Palm Plant for Home"), never a care guide; WITHOUT the brand name (the site appends " | PlantAtHome").',
            'seo_description'   => '"seo_description": a plain-text meta description of 120-155 characters (about 22 words) on one line, written to make a shopper click: name the plant, one or two real qualities, and that it can be bought online. Do not start with "Discover".',
            'hindi_name'        => '"hindi_name": the plant\'s Hindi name in Devanagari; never null for a real plant. If hinglish_name is already on the page, write that same name in Devanagari. Otherwise use an established Hindi common name only when one really exists and is widely used in India (e.g. तुलसी, गुड़हल, घृतकुमारी, नाग पौधा, सुपारी पाम, मोगरा); if none exists, transliterate the product name exactly as given: every word, in the same order, sound for sound as Hindi speakers say it, letters and abbreviations included (e.g. "Lucky Bamboo" -> लकी बैम्बू, "Boston Fern" -> बोस्टन फर्न, "Manjula Pothos" -> मंजुला पोथोस, "Calathea Medallion" -> कैलाथिया मेडेलियन, "ZZ Plant" -> ज़ेडज़ेड प्लांट). When transliterating, never translate a word (Plant stays प्लांट, never पौधा; Bamboo stays बैम्बू, never बांस), never make up a Hindi name from what the English means (Morning Glory is मॉर्निंग ग्लोरी, not सुबह की महक) and never swap in another English name. An old Hindi-name value may be given: use it only when it is a genuine Hindi name in Roman letters (e.g. "Gudhal" -> गुड़हल), and ignore it when it is English (e.g. "Umbrella Plant" for Schefflera) or another language. Never Roman letters, and never a generic word on its own such as पौधा, पेड़ or फूल.',
            'hinglish_name'     => '"hinglish_name": the same name as hindi_name in Roman letters; never null for a real plant. For an established Hindi name, its usual Roman spelling as Indians type it (e.g. "Tulsi", "Gudhal", "Nag Paudha"); for a transliteration, the product name copied letter for letter (e.g. "Lucky Bamboo", "Peperomia", never "Peparomia"). Never Devanagari.',
            'scientific_name'   => '"scientific_name": the accepted botanical name as "Genus species" (a cultivar may follow in single quotes), without the author citation; and "scientific_name_confidence": "high", "medium" or "low" - "high" only when the product name identifies the species unambiguously.',
            'sunlight'          => '"sunlight": exactly one of these values, or null: ' . $choices('sunlight'),
            'water_requirement' => '"water_requirement": exactly one of these values, or null: ' . $choices('water_requirement'),
        ];
        $fields = array_values(array_intersect(array_keys($spec), (array) ($request->fields ?? [])));

        $system = "You are a horticulturist writing product content for PlantAtHome, an online plant nursery in India.\n"
            . "Respond ONLY with one JSON object containing exactly these keys:\n- "
            . implode("\n- ", array_map(fn ($f) => $spec[$f], $fields)) . "\n\n"
            . "Rules:\n"
            . "- Use null for any key you are not confident about. Never guess. (hindi_name and hinglish_name are the exception: when no Hindi name exists, the transliteration is always known.)\n"
            . "- If the product is not one real, single plant (a combo or set of several plants, a mix, a pot, a tool, a demo or test entry), return null for EVERY key.\n"
            . "- No medical, health, toxicity or pet/child-safety claims. Never use these words: cure, heal, medicinal, remedy, therapy, disease, diabetes, cancer, blood pressure, immunity, ayurvedic, toxic, non-toxic, poisonous, pet-safe, pet-friendly, safe for pets or kids.\n"
            . "- Mention edible or culinary use only for plants commonly grown as herbs or vegetables.\n"
            . "- No prices, discounts, guarantees or delivery promises.\n"
            . "- Everything except hindi_name is in English (hinglish_name is Hindi in Roman letters).";

        $user = "Product name: {$request->name}\n";
        if (!empty($context['master'])) {
            $user .= "Variety of: {$context['master']['name']}" . (!empty($context['master']['scientific_name']) ? " ({$context['master']['scientific_name']})" : '') . "\n";
        }
        if (!empty($context['categories'])) {
            $user .= 'Categories: ' . implode(', ', $context['categories']) . "\n";
        }
        if (!empty($context['known'])) {
            $user .= "Already on the product page (stay consistent with it):\n";
            foreach ($context['known'] as $field => $value) {
                $user .= "- {$field}: {$value}\n";
            }
        }
        if (!empty($context['old_hindi_name'])) {
            $user .= "Old Hindi-name value, not in Devanagari (unreliable: it may be English or another language): {$context['old_hindi_name']}\n";
        }
        if (!empty($context['description_excerpt'])) {
            $user .= "Current description (excerpt): {$context['description_excerpt']}\n";
        }

        $response = $this->openAiClient->chat()->create(array_merge($this->textParams(1200, 0.3), [
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
        ]));

        $usage = [
            'prompt_tokens'     => (int) $response->usage->promptTokens,
            'completion_tokens' => (int) $response->usage->completionTokens,
        ];
        $choice = $response->choices[0] ?? null;
        if ($choice && $choice->finishReason === 'length') {
            throw new InvalidAiOutput('AI reply was cut off (finish_reason=length).', $usage);
        }
        $parsed = json_decode((string) ($choice->message->content ?? ''), true);
        if (!is_array($parsed)) {
            throw new InvalidAiOutput('AI reply was not valid JSON.', $usage);
        }

        $confidence = $parsed['scientific_name_confidence'] ?? null;

        return [
            'fields'                     => array_combine($fields, array_map(fn ($f) => $parsed[$f] ?? null, $fields)),
            'scientific_name_confidence' => is_string($confidence) ? strtolower(trim($confidence)) : null,
            'usage'                      => $usage,
            'model'                      => $response->model,
        ];
    }
}
