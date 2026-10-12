<?php

namespace Marvel\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\PlantAttribute;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductContentBatch;
use Marvel\Database\Models\ProductContentJob;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Type;
use Marvel\Jobs\GenerateProductContentBatchJob;

/**
 * The single answer to "which AI content is this plant missing?". The stats
 * strip, the run's eligibility walk, the job's re-check and its completion
 * check all call this class, so they can never disagree about what "missing"
 * means. It also owns the server-side validation of AI output and the
 * fill-only-if-still-empty write.
 */
class MissingPlantContent
{
    /** Columns on `products`. */
    public const PRODUCT_FIELDS = ['description', 'seo_title', 'seo_description'];

    /** Columns on `plant_attributes`. */
    public const ATTRIBUTE_FIELDS = ['hindi_name', 'hinglish_name', 'scientific_name', 'sunlight', 'water_requirement'];

    /** Every field a plant needs, in display order. */
    public const FIELDS = ['description', 'seo_title', 'seo_description', 'hindi_name', 'hinglish_name', 'scientific_name', 'sunlight', 'water_requirement'];

    /** Stand-ins that mean "nobody filled this in" (compared lower-cased). */
    private const PLACEHOLDERS = [
        'n/a', 'na', 'n.a', 'n.a.', 'not available', 'not applicable', 'none', 'null', 'nil', 'undefined',
        '-', '--', '---', '—', '–', '.', '..', '...', '…', '?', 'tbd', 'tba', 'todo', 'unknown', 'coming soon', 'xxx',
    ];

    /**
     * A Hindi "name" made only of these words is a category, not a name.
     * पेड़ and झाड़ी appear twice: precomposed ड़ (U+095C) and ड + nukta.
     * Not बेल: it is also the real name of Bael (Aegle marmelos).
     */
    private const GENERIC_HINDI = [
        'पौधा', 'पौधे', 'फूल', 'घास', 'प्लांट',
        "\u{092A}\u{0947}\u{095C}", "\u{092A}\u{0947}\u{0921}\u{093C}",
        "\u{091D}\u{093E}\u{095C}\u{0940}", "\u{091D}\u{093E}\u{0921}\u{093C}\u{0940}",
    ];

    /**
     * Claims generated copy must never make. Narrowed on purpose: culinary and
     * edible words are allowed so herb copy isn't rejected, and "disease" is
     * not banned because "disease-resistant" is ordinary plant copy.
     */
    private const BANNED_CLAIMS = '/\b(?:cur(?:e|es|ed|ing)|heal(?:s|ed|ing)?|medicinal|remed(?:y|ies)|therap(?:y|ies|eutic)|diabet\w*|cancers?|blood\s+pressure|immun(?:e|ity)|ayurved\w*|(?:non[\s-]?)?toxic\w*|poisonous|pet[\s-]?(?:safe|friendly)|safe\s+(?:for|around|with)\s+(?:pets?|kids?|children|cats?|dogs?))\b/i';

    /** Genus + species epithet, optional hybrid sign, infraspecific rank and quoted cultivar. */
    private const BINOMIAL = "/^[A-Z][a-z]+(?:-[a-z]+)?\\s(?:\u{00D7}\\s?)?(?!(?:sp|spp|species|hybrid|cultivar)\\b)[a-z][a-z-]+(?:\\s(?:subsp\\.|ssp\\.|var\\.|f\\.)\\s[a-z][a-z-]+)?(?:\\s['\u{2018}][^'\u{2019}]{1,40}['\u{2019}])?$/u";

    private ?int $plantsTypeId = null;

    /** @param int $chunk rows per catalogue scan query (injectable so tests can prove the scan is chunked) */
    public function __construct(private int $chunk = 500)
    {
    }

    /* ── what "missing" means ─────────────────────────────────────────────── */

    /**
     * Missing = null, empty, whitespace only (NBSP and zero-width characters
     * included), empty once HTML tags are stripped, or a placeholder such as
     * "N/A". 0 and false are real answers.
     */
    public function isMissing(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return false;
        }
        if (is_array($value)) {
            return $value === [];
        }

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/[\s\p{Z}\p{Cf}]+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return true;
        }

        $lower = mb_strtolower($text);

        return in_array($lower, self::PLACEHOLDERS, true) || str_starts_with($lower, 'lorem ipsum');
    }

    /**
     * isMissing() plus each field's own rule: a Hindi name with no Devanagari in it
     * ("Nag Paudha", or just "Peace Lily") is not a Hindi name yet (owner 2026-10-12:
     * the seeded names were Hinglish), so the AI replaces it with real Hindi.
     */
    public function isFieldMissing(string $field, mixed $value): bool
    {
        return $this->isMissing($value)
            || ($field === 'hindi_name' && !preg_match('/\p{Devanagari}/u', (string) $value))
            // Hinglish is the same name in Roman letters ("Tulsi"): Devanagari there is misplaced.
            || ($field === 'hinglish_name' && preg_match('/\p{Devanagari}/u', (string) $value));
    }

    /** @return string[] the FIELDS this scope() row is missing */
    public function missingFields(object $plant): array
    {
        return array_values(array_filter(self::FIELDS, fn ($f) => $this->isFieldMissing($f, $plant->{$f} ?? null)));
    }

    /** @return string[] what a run should ask the AI for: missing minus fields flagged for review */
    public function todo(array $missing, array $flags): array
    {
        return array_values(array_diff($missing, $flags));
    }

    /**
     * Fields the AI already declined (or failed to identify) for these plants,
     * from their needs_review rows. Sticky: a later run won't ask again — a
     * human fills them from the edit form.
     *
     * @param  int[] $productIds
     * @return array<int, string[]> product_id => fields
     */
    public function reviewFlags(array $productIds): array
    {
        $flags = [];
        if ($productIds === []) {
            return $flags;
        }

        // Failed rows count too: a field the AI declined stays declined even when another
        // field in the same reply made the row fail.
        ProductContentJob::whereIn('product_id', $productIds)
            ->whereIn('status', [ProductContentJob::STATUS_NEEDS_REVIEW, ProductContentJob::STATUS_FAILED])
            ->get(['product_id', 'changes'])
            ->each(function ($row) use (&$flags) {
                foreach (array_keys((array) ($row->changes['review'] ?? [])) as $field) {
                    $flags[(int) $row->product_id][$field] = true;
                }
            });

        return array_map('array_keys', $flags);
    }

    /* ── the catalogue ────────────────────────────────────────────────────── */

    /**
     * Every plant in the catalogue: type `plants`, default language, not
     * trashed, any status, masters and varieties alike. A plain query (no
     * models), so the full catalogue is never hydrated.
     */
    public function scope(): Builder
    {
        return DB::table('products')
            ->leftJoin('plant_attributes', 'plant_attributes.product_id', '=', 'products.id')
            ->where('products.type_id', $this->plantsTypeId())
            ->where('products.language', DEFAULT_LANGUAGE)
            ->whereNull('products.deleted_at')
            ->select([
                'products.id', 'products.name', 'products.master_product_id',
                'products.description', 'products.seo_title', 'products.seo_description',
                'plant_attributes.hindi_name', 'plant_attributes.hinglish_name', 'plant_attributes.scientific_name',
                'plant_attributes.sunlight', 'plant_attributes.water_requirement',
            ]);
    }

    /** One plant's scope() row, or null once it is trashed / no longer a plant. */
    public function find(int $productId): ?object
    {
        return $this->scope()->where('products.id', $productId)->first();
    }

    /**
     * Walk the catalogue in id-ordered chunks.
     *
     * @param callable(object $plant, string[] $missing, string[] $todo, string[] $flags): void $fn
     */
    public function eachPlant(callable $fn): void
    {
        $this->scope()->chunkById($this->chunk, function ($plants) use ($fn) {
            $flags = $this->reviewFlags($plants->pluck('id')->map(fn ($id) => (int) $id)->all());
            foreach ($plants as $plant) {
                $missing = $this->missingFields($plant);
                $flagged = $flags[(int) $plant->id] ?? [];
                $fn($plant, $missing, $this->todo($missing, $flagged), $flagged);
            }
        }, 'products.id', 'id');
    }

    /** The admin stats strip — one chunked pass, no AI calls. */
    public function stats(): array
    {
        $stats = [
            'total' => 0, 'complete' => 0, 'missing_any' => 0, 'missing_hindi' => 0, 'missing_other' => 0,
            'missing_both' => 0, 'needs_review' => 0, 'ready' => 0, 'ready_missing_hindi' => 0, 'processing' => 0,
        ];
        $byField = array_fill_keys(self::FIELDS, 0);

        // Plants already queued in the active run aren't "ready" for another one.
        $active = ProductContentBatch::activeMissingRun();
        $open   = $active
            ? ProductContentJob::where('batch_id', $active->id)
                ->whereIn('status', ProductContentJob::OPEN_STATUSES)
                ->pluck('product_id')->flip()->all()
            : [];
        $stats['processing'] = count($open);

        $this->eachPlant(function ($plant, array $missing, array $todo, array $flags) use (&$stats, &$byField, $open) {
            $stats['total']++;
            if ($missing === []) {
                $stats['complete']++;
                return;
            }

            $hindi = in_array('hindi_name', $missing, true);
            $other = count($missing) > ($hindi ? 1 : 0);
            $stats['missing_any']++;
            $stats['missing_hindi'] += (int) $hindi;
            $stats['missing_other'] += (int) $other;
            $stats['missing_both']  += (int) ($hindi && $other);
            foreach ($missing as $field) {
                $byField[$field]++;
            }

            if (array_intersect($missing, $flags)) {
                $stats['needs_review']++;
            }
            if ($todo !== [] && !isset($open[$plant->id])) {
                $stats['ready']++;
                $stats['ready_missing_hindi'] += (int) in_array('hindi_name', $todo, true);
            }
        });

        // The active run first: "Retry failed" can re-open an older run, and the panel must follow it.
        $latest = $active ?? ProductContentBatch::latestMissingRun();

        return $stats + [
            'missing_by_field' => $byField,
            'fields'           => self::FIELDS,
            // Same switch Marvel\Ai\Base enforces before any AI call.
            'ai_enabled'       => (bool) data_get(Settings::first(), 'options.useAi', false),
            'latest_run'       => $latest ? [
                'id'           => $latest->id,
                'display_id'   => $latest->display_id,
                'status'       => $latest->status,
                'created_at'   => optional($latest->created_at)->toJSON(),
                'completed_at' => optional($latest->completed_at)->toJSON(),
            ] : null,
        ];
    }

    /**
     * Every plant with a missing field that a run flagged for review, across all
     * runs, with the reason the AI gave. A later run never queues these again, so
     * this is where an admin finds them to fill in by hand.
     *
     * @return array{total: int, data: array<int, array{product_id: int, product_name: string, product_slug: ?string, fields: string[], reasons: array<string, string>}>}
     */
    public function reviewList(int $limit = 500): array
    {
        $items = [];
        $total = 0;
        $this->eachPlant(function ($plant, array $missing, array $todo, array $flags) use (&$items, &$total, $limit) {
            $open = array_values(array_intersect($missing, $flags));
            if ($open === []) {
                return;
            }
            $total++;
            if (count($items) < $limit) {
                $items[(int) $plant->id] = ['product_id' => (int) $plant->id, 'product_name' => (string) $plant->name, 'fields' => $open];
            }
        });

        if ($items !== []) {
            $ids     = array_keys($items);
            $slugs   = DB::table('products')->whereIn('id', $ids)->pluck('slug', 'id');
            $reasons = [];
            ProductContentJob::whereIn('product_id', $ids)
                ->whereIn('status', [ProductContentJob::STATUS_NEEDS_REVIEW, ProductContentJob::STATUS_FAILED])
                ->orderBy('id') // the latest reason wins
                ->get(['product_id', 'changes'])
                ->each(function ($row) use (&$reasons) {
                    foreach ((array) ($row->changes['review'] ?? []) as $field => $why) {
                        $reasons[(int) $row->product_id][$field] = (string) $why;
                    }
                });
            foreach ($items as $id => &$item) {
                $item['product_slug'] = $slugs[$id] ?? null;
                $item['reasons']      = array_intersect_key($reasons[$id] ?? [], array_flip($item['fields']));
            }
            unset($item);
        }

        return ['total' => $total, 'data' => array_values($items)];
    }

    /**
     * What the AI is told about the plant besides its name: fields it already
     * has (so new content stays consistent), a visible description excerpt,
     * up to five category names and, for a variety, its master plant.
     */
    public function context(object $plant): array
    {
        $known = [];
        $hint  = null;
        $same  = fn ($a, $b) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $a))) === mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $b)));
        foreach (array_diff(self::FIELDS, ['description']) as $field) {
            $value = $plant->{$field} ?? null;
            if (!$this->isFieldMissing($field, $value)) {
                $known[$field] = trim((string) $value);
            } elseif ($field === 'hindi_name' && !$this->isMissing($value) && !$same($value, $plant->name ?? '')) {
                // A hint, kept apart from the known values: Hinglish ("Nag Paudha"), but seeded rows also
                // hold English or Marathi names. The product's own name repeated made the AI decline.
                $hint = trim((string) $value);
            }
        }

        $excerpt = null;
        if (!$this->isMissing($plant->description ?? null)) {
            $text    = html_entity_decode(strip_tags((string) $plant->description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $excerpt = mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 0, 600);
        }

        $categories = DB::table('category_product')
            ->join('categories', 'categories.id', '=', 'category_product.category_id')
            ->where('category_product.product_id', $plant->id)
            ->orderBy('categories.id')
            ->limit(5)
            ->pluck('categories.name')
            ->filter()
            ->values()
            ->all();

        $master = null;
        if (!empty($plant->master_product_id)) {
            $row = DB::table('products')
                ->leftJoin('plant_attributes', 'plant_attributes.product_id', '=', 'products.id')
                ->where('products.id', $plant->master_product_id)
                ->first(['products.name', 'plant_attributes.scientific_name']);
            if ($row) {
                $master = ['name' => $row->name, 'scientific_name' => $row->scientific_name];
            }
        }

        return array_filter([
            'known'               => $known,
            'old_hindi_name'      => $hint,
            'description_excerpt' => $excerpt,
            'categories'          => $categories,
            'master'              => $master,
        ]);
    }

    /* ── AI output ────────────────────────────────────────────────────────── */

    /**
     * Server-side check of one AI reply. Only requested fields are considered.
     * null / blank = declined (the plant needs review); a value that breaks a
     * rule = invalid (the row fails with the reason and nothing invalid is
     * stored).
     *
     * @return array{values: array<string, string>, declined: array<string, string>, invalid: array<string, string>}
     */
    public function validate(array $requested, array $fields, ?string $confidence = null): array
    {
        $out = ['values' => [], 'declined' => [], 'invalid' => []];

        foreach (array_intersect(self::FIELDS, $requested) as $field) {
            $value = $fields[$field] ?? null;
            if ($value !== null && !is_string($value)) {
                $out['invalid'][$field] = 'not text';
                continue;
            }
            if ($this->isMissing($value)) {
                $out['declined'][$field] = 'AI was not confident';
                continue;
            }

            [$clean, $problem, $declined] = $this->check($field, $value, $confidence);
            if ($declined !== null) {
                $out['declined'][$field] = $declined;
            } elseif ($problem !== null) {
                $out['invalid'][$field] = $problem;
            } else {
                $out['values'][$field] = $clean;
            }
        }

        return $out;
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} [clean value, invalid reason, declined reason] */
    private function check(string $field, string $value, ?string $confidence): array
    {
        $line = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        switch ($field) {
            case 'description':
                $html    = trim(GenerateProductContentBatchJob::sanitizeDescription($value));
                $visible = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
                if (mb_strlen($visible) < 120) {
                    return [null, 'too short (' . mb_strlen($visible) . ' visible characters, need 120)', null];
                }
                if (mb_strlen($html) > 10000) {
                    return [null, 'too long', null];
                }
                return [$html, $this->bannedClaim($visible), null];

            case 'seo_title':
            case 'seo_description':
                if ($line !== strip_tags($line) || preg_match('/[\r\n]/', trim($value))) {
                    return [null, 'must be plain text on one line', null];
                }
                if ($field === 'seo_title') {
                    // The storefront appends " | PlantAtHome" itself.
                    $line = trim(preg_replace('/\s*(?:[|\-–—:·]\s*plant\s*at\s*home|plantathome)(?:\.(?:com|in))?\s*$/iu', '', $line) ?? $line);
                }
                [$min, $max] = $field === 'seo_title' ? [10, 55] : [100, 170];
                $len = mb_strlen($line);
                if ($len < $min || $len > $max) {
                    return [null, "length {$len} (allowed {$min}-{$max})", null];
                }
                return [$line, $this->bannedClaim($line), null];

            case 'hindi_name':
                if (!preg_match('/^[\p{Devanagari}\x{200C}\x{200D}\s\-\/(),\[\]]+$/u', $line) || !preg_match('/\p{Devanagari}/u', $line)) {
                    return [null, 'not Devanagari', null];
                }
                if (mb_strlen($line) > 60) {
                    return [null, 'longer than 60 characters', null];
                }
                $words = preg_split('/[\s\-\/(),\[\]]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (array_diff($words, self::GENERIC_HINDI) === []) {
                    return [null, 'a generic word, not a plant name', null];
                }
                return [$line, null, null];

            case 'hinglish_name':
                // The Hindi name as Indians type it in Roman letters ("Tulsi", "Nag Paudha").
                if (!preg_match("/^[A-Za-z][A-Za-z\s\-'.()]*$/u", $line)) {
                    return [null, 'not in Roman letters', null];
                }
                if (mb_strlen($line) < 2 || mb_strlen($line) > 60) {
                    return [null, 'length ' . mb_strlen($line) . ' (allowed 2-60)', null];
                }
                if (in_array(mb_strtolower($line), ['paudha', 'paudhe', 'ped', 'phool', 'plant', 'plants'], true)) {
                    return [null, 'a generic word, not a plant name', null];
                }
                return [$line, null, null];

            case 'scientific_name':
                if (strtolower((string) $confidence) !== 'high') {
                    return [null, null, 'scientific name confidence ' . ($confidence ?: 'missing')];
                }
                if (!preg_match(self::BINOMIAL, $line)) {
                    return [null, null, 'not a full binomial'];
                }
                return [$line, null, null];

            case 'sunlight':
            case 'water_requirement':
                $allowed = array_keys((array) config("content-batches.plant_vocabulary.{$field}", []));
                foreach ($allowed as $term) {
                    if (strcasecmp($term, $line) === 0) {
                        return [$term, null, null]; // store the exact config spelling
                    }
                }
                return [null, 'not one of: ' . implode(', ', $allowed), null];
        }

        return [null, 'unknown field', null];
    }

    private function bannedClaim(string $text): ?string
    {
        return preg_match(self::BANNED_CLAIMS, $text, $m) ? "banned claim \"{$m[0]}\"" : null;
    }

    /* ── the write ────────────────────────────────────────────────────────── */

    /**
     * Store validated values, each only if that field is STILL missing under a
     * row lock — a manual edit (or a duplicate job run) is never overwritten.
     * Products save through Eloquent so the translation observer still sees
     * the description change; a new hindi_name is mirrored into
     * regional_names.hi exactly as ProductRepository does.
     *
     * @param  array<string, string> $values
     * @return string[] the fields actually written
     */
    public function write(int $productId, array $values): array
    {
        return DB::transaction(function () use ($productId, $values) {
            // select * on purpose: Product has fluent meta, so assigning a column
            // that wasn't loaded would be stored as a meta row instead.
            $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
            if (!$product) {
                return [];
            }

            $written = [];
            foreach (array_intersect_key($values, array_flip(self::PRODUCT_FIELDS)) as $field => $value) {
                if ($this->isFieldMissing($field, $product->getAttributes()[$field] ?? null)) {
                    $product->{$field} = $value;
                    $written[] = $field;
                }
            }
            if ($product->isDirty()) {
                $product->save();
            }

            $attributeValues = array_intersect_key($values, array_flip(self::ATTRIBUTE_FIELDS));
            if ($attributeValues === []) {
                return $written;
            }

            // Unique product_id makes this safe against a concurrent creator.
            DB::table('plant_attributes')->insertOrIgnore([
                'product_id' => $productId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $attribute = PlantAttribute::where('product_id', $productId)->lockForUpdate()->first();

            foreach ($attributeValues as $field => $value) {
                $old = $attribute->getAttributes()[$field] ?? null;
                if (!$this->isFieldMissing($field, $old)) {
                    continue;
                }
                $attribute->{$field} = $value;
                $written[] = $field;
                if ($field === 'hindi_name') {
                    // The Hinglish/English name it replaces stays searchable: shoppers type "tulsi".
                    $old = trim((string) $old);
                    if ($old !== '' && !$this->isMissing($old)) {
                        $names = (array) json_decode((string) ($attribute->getAttributes()['common_names'] ?? '[]'), true);
                        if (!in_array(mb_strtolower($old), array_map(fn ($n) => mb_strtolower((string) $n), $names), true)) {
                            $names[] = $old;
                        }
                        $attribute->common_names = json_encode(array_values($names), JSON_UNESCAPED_UNICODE);
                    }
                    $regional = (array) ($attribute->regional_names ?? []);
                    $regional['hi'] = $value;
                    $attribute->regional_names = $regional;
                }
            }
            if ($attribute->isDirty()) {
                $attribute->save();
            }

            return $written;
        });
    }

    private function plantsTypeId(): int
    {
        return $this->plantsTypeId ??= (int) Type::where('slug', 'plants')->where('language', 'en')->value('id');
    }
}
