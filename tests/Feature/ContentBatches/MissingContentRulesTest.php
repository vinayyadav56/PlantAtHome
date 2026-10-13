<?php

namespace Tests\Feature\ContentBatches;

use Marvel\Ai\Openai;
use Marvel\Services\MissingPlantContent;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;

/**
 * What "missing" means and what AI output may be stored. One class answers
 * both for the stats, the run and the job, so these rules are the contract.
 */
final class MissingContentRulesTest extends ContentBatchTestCase
{
    private MissingPlantContent $content;

    protected function setUp(): void
    {
        parent::setUp();
        $this->content = new MissingPlantContent();
    }

    public function test_null_blank_whitespace_placeholders_and_empty_html_are_missing(): void
    {
        foreach ([
            null, '', '   ', "\u{00A0}\u{200B} \n\t", "\u{FEFF}",
            'N/A', 'n/a', 'NA', 'none', 'Null', '-', '—', 'TBD', 'unknown', 'Coming soon', ' Lorem ipsum dolor sit amet ',
            '<p></p>', '<p>&nbsp;</p>', '<div><br></div>', '<p> N/A </p>', [],
        ] as $value) {
            $this->assertTrue($this->content->isMissing($value), 'expected missing: ' . json_encode($value));
        }
    }

    public function test_zero_false_and_roman_hindi_names_are_present(): void
    {
        foreach ([0, '0', 0.0, false, 'Nag Paudha', 'Tulsi', 'तुलसी', '<p>Real copy.</p>', 'Low'] as $value) {
            $this->assertFalse($this->content->isMissing($value), 'expected present: ' . json_encode($value));
        }

        // Owner 2026-10-12: a Hindi name must be in Devanagari, and the Hinglish name in
        // Roman letters. A Roman/English "Hindi name" (the seeded ones) is not one yet.
        $plant = (object) array_merge(['id' => 1, 'name' => 'Snake Plant'], $this->completeContent()['product'], $this->completeContent()['attribute']);
        $this->assertSame([], $this->content->missingFields($plant));
        $plant->hindi_name = 'Nag Paudha';
        $this->assertSame(['hindi_name'], $this->content->missingFields($plant));
        $plant->hindi_name    = 'नाग पौधा';
        $plant->hinglish_name = 'नाग पौधा';
        $this->assertSame(['hinglish_name'], $this->content->missingFields($plant));
        $plant->hinglish_name = 'Nag Paudha';
        $plant->sunlight      = "\u{00A0}";
        $this->assertSame(['sunlight'], $this->content->missingFields($plant));
    }

    public function test_hindi_name_must_be_devanagari_and_a_real_name(): void
    {
        $this->assertInvalid('hindi_name', 'Tulsi', 'not Devanagari');
        $this->assertInvalid('hindi_name', 'तुलसी (Tulsi)', 'not Devanagari');
        foreach ([
            'पौधा', 'पौधे', 'फूल', 'घास', 'प्लांट', 'फूल पौधा',
            "\u{092A}\u{0947}\u{095C}", "\u{092A}\u{0947}\u{0921}\u{093C}",                 // पेड़, both nukta encodings
            "\u{091D}\u{093E}\u{095C}\u{0940}", "\u{091D}\u{093E}\u{0921}\u{093C}\u{0940}", // झाड़ी, both encodings
        ] as $generic) {
            $this->assertInvalid('hindi_name', $generic, 'generic');
        }
        $this->assertInvalid('hindi_name', str_repeat('तुलसी ', 12), 'longer than 60');

        $this->assertValid('hindi_name', ' तुलसी ', 'तुलसी');
        $this->assertValid('hindi_name', 'मनी प्लांट', 'मनी प्लांट'); // a transliteration may contain a generic word
        $this->assertValid('hindi_name', 'बेल', 'बेल'); // Bael (Aegle marmelos): a real name, not "vine"
        $this->assertValid('hindi_name', 'नाग-पौधा', 'नाग-पौधा');
    }

    public function test_scientific_name_needs_high_confidence_and_a_full_binomial(): void
    {
        $this->assertValid('scientific_name', 'Ocimum tenuiflorum', 'Ocimum tenuiflorum', 'high');
        $this->assertValid('scientific_name', "Epipremnum aureum 'Marble Queen'", "Epipremnum aureum 'Marble Queen'", 'HIGH');
        $this->assertValid('scientific_name', 'Rosa × damascena', 'Rosa × damascena', 'high');
        $this->assertValid('scientific_name', 'Ficus elastica var. variegata', 'Ficus elastica var. variegata', 'high');

        $this->assertDeclined('scientific_name', 'Ocimum tenuiflorum', 'medium');
        $this->assertDeclined('scientific_name', 'Ocimum tenuiflorum', null);
        foreach (['Ficus', 'Ficus sp.', 'Ficus spp', 'Ficus species', 'ocimum tenuiflorum', 'Ocimum tenuiflorum L.'] as $notBinomial) {
            $this->assertDeclined('scientific_name', $notBinomial, 'high');
        }
    }

    public function test_sunlight_and_water_must_come_from_the_vocabulary_in_its_spelling(): void
    {
        $this->assertValid('sunlight', 'full sun', 'Full Sun');
        $this->assertValid('sunlight', 'BRIGHT INDIRECT', 'Bright Indirect');
        $this->assertValid('water_requirement', 'moderate', 'Moderate');

        $this->assertInvalid('sunlight', 'Bright Light', 'not one of');
        $this->assertInvalid('sunlight', 'Full sun to partial shade', 'not one of');
        $this->assertInvalid('water_requirement', 'Weekly', 'not one of');
    }

    public function test_seo_fields_are_plain_one_line_text_within_length(): void
    {
        $this->assertInvalid('seo_title', '<b>Snake Plant</b> for Sale', 'plain text');
        $this->assertInvalid('seo_title', "Snake Plant\nfor Sale", 'plain text');
        $this->assertInvalid('seo_title', 'Snake', 'length 5');
        $this->assertInvalid('seo_title', str_repeat('Snake Plant ', 5), 'length 59');
        // The storefront appends the brand itself — a suffix the model adds is stripped.
        $this->assertValid('seo_title', 'Snake Plant for Sale | PlantAtHome', 'Snake Plant for Sale');
        $this->assertValid('seo_title', 'Snake Plant for Sale - Plant At Home', 'Snake Plant for Sale');

        $this->assertInvalid('seo_description', 'Too short.', 'length 10');
        $this->assertInvalid('seo_description', str_repeat('Lovely plant. ', 13), 'length 181');
        $this->assertInvalid('seo_description', 'A sturdy, glossy plant for bright rooms and busy people.', 'length 56');
        $meta = 'A sturdy, glossy rubber plant for bright rooms and busy people, delivered potted and ready to grow. Buy it online.';
        $this->assertInvalid('seo_description', "<p>{$meta}</p>", 'plain text');
        $this->assertValid('seo_description', $meta, $meta);
        $short = 'Lemon plants give fragrant blossoms and fresh fruit on a sunny balcony. Buy yours online today.';
        $this->assertValid('seo_description', $short, $short); // 95: the model's short end, fine as a meta description
    }

    public function test_hinglish_name_is_roman_letters_and_a_real_name(): void
    {
        $this->assertValid('hinglish_name', 'Tulsi', 'Tulsi');
        $this->assertValid('hinglish_name', 'Nag Paudha', 'Nag Paudha');
        $this->assertValid('hinglish_name', 'Snake Plant', 'Snake Plant');
        $this->assertInvalid('hinglish_name', 'तुलसी', 'not in Roman letters');
        $this->assertInvalid('hinglish_name', 'Paudha', 'generic');
        $this->assertInvalid('hinglish_name', str_repeat('Tulsi ', 13), 'length');
    }

    public function test_a_respelled_or_reordered_hinglish_name_becomes_the_product_name(): void
    {
        $hinglish = fn (string $value, string $product) => $this->content->validate(['hinglish_name'], ['hinglish_name' => $value], null, $product)['values']['hinglish_name'] ?? null;

        $this->assertSame('Rosemary', $hinglish('Rosmary', 'Rosemary'));
        $this->assertSame('Syngonium Pink', $hinglish('Singonium Pink', 'Syngonium Pink'));
        $this->assertSame('Calathea Medallion', $hinglish('Medallion Calathea', 'Calathea Medallion'));
        // A real Hindi name is further from the product name, or shorter, and is kept.
        $this->assertSame('Papita', $hinglish('Papita', 'Papaya'));
        $this->assertSame('Mogra', $hinglish('Mogra', 'Jasmine Mogra'));
        $this->assertSame('Arjun', $hinglish('Arjun', 'Arjun Tree'));
        $this->assertSame('Cebu Blue', $hinglish('Cebu Blue', 'Cebu Blue Pothos'), 'a dropped word is left as the AI wrote it, like its Hindi name');
        $this->assertSame('Kadi Patta', $hinglish('Kadi Patta', 'Curry Leaf'));
        $this->assertSame('Nag Paudha', $hinglish('Nag Paudha', 'Snake Plant'));
        // A product name that is not valid Hinglish itself is never copied in.
        $this->assertSame('Zz Raven', $hinglish('Zz Raven', 'ZZ Raven 2'));
    }

    public function test_a_description_of_only_an_image_or_a_video_is_content(): void
    {
        $this->assertFalse($this->content->isMissing('<p><img src="https://cdn.example/care-chart.png"></p>'));
        $this->assertFalse($this->content->isMissing('<iframe class="ql-video" src="https://www.youtube.com/embed/x"></iframe><p><br></p>'));
        $this->assertTrue($this->content->isMissing('<p><br></p>'));
    }

    public function test_names_with_digits_commas_and_x_hybrids_are_accepted(): void
    {
        // From the production catalogue (2026-10-13): SKUs with digits, two-name products, hybrids written "x".
        $this->assertValid('hindi_name', 'बेगोनिया यू400', 'बेगोनिया यू400');
        $this->assertValid('hindi_name', '7 लेयर लकी बैम्बू', '7 लेयर लकी बैम्बू');
        $this->assertInvalid('hindi_name', '400', 'not Devanagari');
        $this->assertValid('hinglish_name', 'Begonia U400', 'Begonia U400');
        $this->assertValid('hinglish_name', '7 Layer Lucky Bamboo', '7 Layer Lucky Bamboo');
        $this->assertValid('hinglish_name', 'Agar Wood, Eagle Wood', 'Agar Wood, Eagle Wood');
        $this->assertInvalid('hinglish_name', '400', 'not in Roman letters');
        $this->assertValid('scientific_name', 'Abelia x grandiflora', 'Abelia x grandiflora');
        $this->assertValid('scientific_name', 'Abelia × grandiflora', 'Abelia × grandiflora');
        $this->assertDeclined('scientific_name', 'Acalypha wilkesiana macrophylla rosea', 'high'); // not a binomial: left for a person
    }

    public function test_banned_claims_are_rejected_but_culinary_copy_is_allowed(): void
    {
        $long = ' It grows well in a bright spot with regular watering and rewards you with fresh green leaves all year round.';
        foreach ([
            '<p>Its leaves cure coughs.' . $long . '</p>',
            '<p>A medicinal herb.' . $long . '</p>',
            '<p>Non-toxic and gentle.' . $long . '</p>',
            '<p>Pet-friendly greenery.' . $long . '</p>',
            '<p>Perfectly safe for kids.' . $long . '</p>',
            '<p>Used in Ayurvedic practice.' . $long . '</p>',
            '<p>Boosts immunity.' . $long . '</p>',
        ] as $claim) {
            $this->assertInvalid('description', $claim, 'banned claim');
        }
        $this->assertInvalid('seo_description', 'A hardy, poisonous-looking succulent for sunny windowsills everywhere, delivered potted and ready to grow at home.', 'banned claim');

        $culinary = '<p>Tulsi is an edible culinary herb: its fresh leaves go into cooking and herbal tea, and it keeps a sunny kitchen windowsill healthy-looking.' . $long . '</p>';
        $this->assertValid('description', $culinary, $culinary);
    }

    public function test_description_is_sanitized_and_needs_120_visible_characters(): void
    {
        $this->assertInvalid('description', '<p>Lovely.</p>', 'too short');
        $this->assertInvalid('description', '<script>' . str_repeat('alert(1);', 30) . '</script>', 'too short');

        $body   = str_repeat('Glossy leaves and an easy-going nature make it a favourite. ', 3);
        $result = $this->content->validate(['description'], ['description' => "<p onclick=\"steal()\">{$body}</p><script>alert(1)</script><iframe src=\"x\"></iframe>"]);
        $saved  = $result['values']['description'];
        $this->assertStringNotContainsString('<script', $saved);
        $this->assertStringNotContainsString('alert(1)', $saved);
        $this->assertStringNotContainsString('<iframe', $saved);
        $this->assertStringNotContainsString('onclick', $saved);
        $this->assertStringContainsString('<p>Glossy leaves', $saved);
    }

    public function test_only_requested_fields_count_and_null_is_declined(): void
    {
        $result = $this->content->validate(['hindi_name', 'sunlight', 'water_requirement'], [
            'hindi_name'        => 'तुलसी',
            'sunlight'          => null,
            'water_requirement' => ['Low'],
            'description'       => 'not requested, so never stored or judged',
        ]);

        $this->assertSame(['hindi_name' => 'तुलसी'], $result['values']);
        $this->assertSame(['sunlight'], array_keys($result['declined']));
        $this->assertSame(['water_requirement' => 'not text'], $result['invalid']);
    }

    /** The real provider against OpenAI's own client fake (no network): JSON mode, only requested keys, no silent truncation. */
    public function test_gpt_5_models_get_their_own_request_settings(): void
    {
        // Reasoning models reject max_tokens and temperature 0.3 (a 400 on every plant).
        config(['shop.openai.secret_Key' => 'sk-test']);
        $reply = CreateResponse::fake([
            'model'   => 'gpt-5.4-mini-2026-03-17',
            'choices' => [['message' => ['content' => json_encode(['hindi_name' => 'तुलसी'])], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 300, 'completion_tokens' => 40, 'total_tokens' => 340],
        ]);
        $request = (object) ['name' => 'Tulsi', 'fields' => ['hindi_name'], 'context' => [], 'vocabulary' => config('content-batches.plant_vocabulary')];

        foreach (['gpt-5.4-mini' => 'none', 'gpt-5-mini' => 'minimal'] as $model => $effort) {
            config(['content-batches.text_model' => $model]);
            $client   = new ClientFake([$reply]);
            $provider = new Openai();
            $property = new \ReflectionProperty(Openai::class, 'openAiClient');
            $property->setAccessible(true);
            $property->setValue($provider, $client);

            $this->assertSame(['hindi_name' => 'तुलसी'], $provider->generatePlantContent($request)['fields']);
            $client->assertSent(Chat::class, fn ($method, $params) => $params['model'] === $model
                && $params['reasoning_effort'] === $effort
                && $params['max_completion_tokens'] === 2200
                && !array_key_exists('max_tokens', $params)
                && !array_key_exists('temperature', $params)
                && $params['response_format'] === ['type' => 'json_object']);
        }
    }

    public function test_the_openai_provider_asks_for_json_and_rejects_unusable_replies(): void
    {
        // The classic request settings (temperature, max_tokens); GPT-5 has its own test above.
        config(['shop.openai.secret_Key' => 'sk-test', 'content-batches.text_model' => 'gpt-4o-mini']);
        $reply = fn (string $content, string $finish = 'stop') => CreateResponse::fake([
            'model'   => 'gpt-4o-mini',
            'choices' => [['message' => ['content' => $content], 'finish_reason' => $finish]],
            'usage'   => ['prompt_tokens' => 321, 'completion_tokens' => 123, 'total_tokens' => 444],
        ]);
        $client = new ClientFake([
            $reply(json_encode(['hindi_name' => 'तुलसी', 'sunlight' => 'Full Sun', 'scientific_name_confidence' => 'HIGH', 'description' => 'not asked for'])),
            $reply('{"hindi_name": "तुल', 'length'),
            $reply('Sorry, I cannot help with that.'),
        ]);
        $provider = new Openai();
        $property = new \ReflectionProperty(Openai::class, 'openAiClient');
        $property->setAccessible(true);
        $property->setValue($provider, $client);
        $request = (object) [
            'name'       => 'Tulsi',
            'fields'     => ['hindi_name', 'sunlight'],
            'context'    => ['categories' => ['Herbs'], 'known' => ['scientific_name' => 'Ocimum tenuiflorum']],
            'vocabulary' => config('content-batches.plant_vocabulary'),
        ];

        $result = $provider->generatePlantContent($request);

        $this->assertSame(['hindi_name' => 'तुलसी', 'sunlight' => 'Full Sun'], $result['fields'], 'only the requested keys');
        $this->assertSame('high', $result['scientific_name_confidence']);
        $this->assertSame(['prompt_tokens' => 321, 'completion_tokens' => 123], $result['usage']);
        $this->assertSame('gpt-4o-mini', $result['model']);
        $client->assertSent(Chat::class, function ($method, $params) {
            $system = $params['messages'][0]['content'];
            $user   = $params['messages'][1]['content'];

            return $params['response_format'] === ['type' => 'json_object']
                && $params['temperature'] === 0.3
                && str_contains($system, '"hindi_name"') && str_contains($system, 'Devanagari')
                && str_contains($system, '"Bright Indirect" (a bright spot indoors')
                && !str_contains($system, '"description"')
                && str_contains($system, 'a pack, combo, kit') && str_contains($system, '"single_plant"')
                && str_contains($user, 'Product name: Tulsi')
                && str_contains($user, 'Categories: Herbs')
                && str_contains($user, '- scientific_name: Ocimum tenuiflorum');
        });

        foreach (['cut off (finish_reason=length)', 'not valid JSON'] as $why) {
            try {
                $provider->generatePlantContent($request);
                $this->fail("expected an invalid-output error: {$why}");
            } catch (\UnexpectedValueException $e) {
                $this->assertStringContainsString($why, $e->getMessage());
            }
        }
    }

    private function assertValid(string $field, string $value, string $stored, ?string $confidence = 'high'): void
    {
        $result = $this->content->validate([$field], [$field => $value], $confidence);
        $this->assertSame([$field => $stored], $result['values'], "{$field} " . json_encode($value, JSON_UNESCAPED_UNICODE) . ' should be stored: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    private function assertInvalid(string $field, string $value, string $reason): void
    {
        $result = $this->content->validate([$field], [$field => $value], 'high');
        $this->assertSame([], $result['values'], "{$field} " . json_encode($value, JSON_UNESCAPED_UNICODE) . ' must not be stored');
        $this->assertArrayHasKey($field, $result['invalid'], json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString($reason, $result['invalid'][$field]);
    }

    private function assertDeclined(string $field, string $value, ?string $confidence): void
    {
        $result = $this->content->validate([$field], [$field => $value], $confidence);
        $this->assertSame([], $result['values'], "{$field} " . json_encode($value) . ' must not be stored');
        $this->assertArrayHasKey($field, $result['declined'], json_encode($result));
    }
}
