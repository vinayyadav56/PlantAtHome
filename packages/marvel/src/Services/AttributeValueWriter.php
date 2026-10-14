<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\PlantAttributeDefinition;
use Marvel\Database\Models\Product;

/**
 * A product's attribute values ("specifications"), validated against their definitions and
 * written to plant_attribute_product.
 *
 * Payload, one entry per definition being set:
 *   {definition_id, term_ids: int[]}            single / multi (single: at most one)
 *   {definition_id, value: number}              number
 *   {definition_id, value: number, value_max}   range
 *   {definition_id, value: bool}                boolean
 *   {definition_id, value: string}              text
 * An entry with no value clears that definition. Definitions NOT in the payload are left as
 * they are, so changing a product's categories never silently deletes its saved specs.
 */
class AttributeValueWriter
{
    /**
     * Problems with the payload, as [field => message] (empty when it is fine). Required
     * definitions (for the vertical and categories after this save) must have a value when the
     * product is being published; drafts and proposals may be incomplete.
     */
    public function errors(array $values, ?int $typeId, array $categoryIds, bool $publishing, ?int $productId = null): array
    {
        $errors = [];
        $ids = collect($values)->pluck('definition_id')->filter()->map(fn ($v) => (int) $v)->all();
        $definitions = PlantAttributeDefinition::with('terms')->whereIn('id', $ids)->get()->keyBy('id');
        $current = $productId
            ? DB::table('plant_attribute_product')->where('product_id', $productId)->whereNotNull('term_id')->pluck('term_id')->map(fn ($v) => (int) $v)->all()
            : [];

        $submitted = [];
        foreach ($values as $i => $entry) {
            $field = "attribute_values.{$i}";
            $entry = (array) $entry;
            $def = $definitions->get((int) ($entry['definition_id'] ?? 0));
            if (!$def) {
                $errors[$field] = 'Unknown attribute.';
                continue;
            }
            $empty = $this->isEmpty($def, $entry);
            $submitted[$def->id] = !$empty;
            if ($empty) {
                continue;
            }

            if (in_array($def->type, PlantAttributeDefinition::TERM_TYPES, true)) {
                $termIds = array_values(array_unique(array_map('intval', (array) $entry['term_ids'])));
                if ($def->type === 'single' && count($termIds) > 1) {
                    $errors[$field] = "{$def->name}: choose one value.";
                    continue;
                }
                $terms = $def->terms->keyBy('id');
                foreach ($termIds as $termId) {
                    $term = $terms->get($termId);
                    if (!$term) {
                        $errors[$field] = "{$def->name}: that value does not belong to this attribute.";
                        continue 2;
                    }
                    // A retired value may stay on a product that already has it; it cannot be newly picked.
                    if (!$term->is_active && !in_array($termId, $current, true)) {
                        $errors[$field] = "{$def->name}: \"{$term->value}\" is retired.";
                        continue 2;
                    }
                }
            } elseif ($def->type === 'boolean') {
                if (filter_var($entry['value'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
                    $errors[$field] = "{$def->name}: answer yes or no.";
                }
            } elseif (in_array($def->type, ['number', 'range'], true)) {
                $numbers = [$entry['value']];
                if ($def->type === 'range' && ($entry['value_max'] ?? '') !== '' && ($entry['value_max'] ?? null) !== null) {
                    $numbers[] = $entry['value_max'];
                }
                foreach ($numbers as $n) {
                    if (!is_numeric($n)) {
                        $errors[$field] = "{$def->name}: enter a number.";
                        continue 2;
                    }
                    $unit = $def->unit ? " {$def->unit}" : '';
                    if ($def->min_value !== null && (float) $n < $def->min_value) {
                        $errors[$field] = "{$def->name}: at least " . $this->num($def->min_value) . $unit . '.';
                        continue 2;
                    }
                    if ($def->max_value !== null && (float) $n > $def->max_value) {
                        $errors[$field] = "{$def->name}: at most " . $this->num($def->max_value) . $unit . '.';
                        continue 2;
                    }
                }
                if (count($numbers) === 2 && (float) $numbers[0] > (float) $numbers[1]) {
                    $errors[$field] = "{$def->name}: the first number must not be larger than the second.";
                }
            } else {
                $limit = $def->max_length ?: 255;
                if (mb_strlen(trim((string) $entry['value'])) > $limit) {
                    $errors[$field] = "{$def->name}: at most {$limit} characters.";
                }
            }
        }

        if ($publishing) {
            $stored = $productId
                ? DB::table('plant_attribute_product')->where('product_id', $productId)->distinct()->pluck('definition_id')->map(fn ($v) => (int) $v)->flip()->all()
                : [];
            foreach (app(ProductAttributeSchema::class)->forProduct($typeId, $categoryIds) as $def) {
                if (!$def->is_required) {
                    continue;
                }
                $has = array_key_exists($def->id, $submitted) ? $submitted[$def->id] : isset($stored[$def->id]);
                if (!$has) {
                    $errors["attribute_values.required.{$def->code}"] = "{$def->name} is required in this category before the product can be published.";
                }
            }
        }

        return $errors;
    }

    /** Replace the values of the definitions in the payload (an empty entry clears one). */
    public function write(Product $product, array $values): void
    {
        $ids = collect($values)->pluck('definition_id')->filter()->map(fn ($v) => (int) $v)->all();
        $definitions = PlantAttributeDefinition::whereIn('id', $ids)->get()->keyBy('id');
        if ($definitions->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($product, $values, $definitions) {
            foreach ($values as $entry) {
                $entry = (array) $entry;
                $def = $definitions->get((int) ($entry['definition_id'] ?? 0));
                if (!$def) {
                    continue;
                }
                DB::table('plant_attribute_product')->where('product_id', $product->id)->where('definition_id', $def->id)->delete();
                if ($this->isEmpty($def, $entry)) {
                    continue;
                }
                $now = now();
                $row = ['definition_id' => $def->id, 'product_id' => $product->id, 'created_at' => $now, 'updated_at' => $now];
                if (in_array($def->type, PlantAttributeDefinition::TERM_TYPES, true)) {
                    foreach (array_values(array_unique(array_map('intval', (array) $entry['term_ids']))) as $termId) {
                        DB::table('plant_attribute_product')->insert($row + ['term_id' => $termId]);
                    }
                } elseif ($def->type === 'text') {
                    DB::table('plant_attribute_product')->insert($row + ['value_text' => trim((string) $entry['value'])]);
                } else {
                    $number = $def->type === 'boolean'
                        ? (filter_var($entry['value'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0)
                        : (float) $entry['value'];
                    $max = $def->type === 'range' && isset($entry['value_max']) && $entry['value_max'] !== '' ? (float) $entry['value_max'] : null;
                    DB::table('plant_attribute_product')->insert($row + ['value_number' => $number, 'value_number_max' => $max]);
                }
            }
        });

        Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);
    }

    /**
     * A product's values as the admin form edits them: one entry per definition, with term ids
     * or the typed value.
     */
    public function current(int $productId): array
    {
        $rows = DB::table('plant_attribute_product')->where('product_id', $productId)->orderBy('id')->get();
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r->definition_id;
            $out[$id] ??= ['definition_id' => $id, 'term_ids' => [], 'value' => null, 'value_max' => null];
            if ($r->term_id) {
                $out[$id]['term_ids'][] = (int) $r->term_id;
            } elseif ($r->value_number !== null) {
                $out[$id]['value'] = (float) $r->value_number;
                $out[$id]['value_max'] = $r->value_number_max !== null ? (float) $r->value_number_max : null;
            } elseif ($r->value_text !== null) {
                $out[$id]['value'] = (string) $r->value_text;
            }
        }

        return array_values($out);
    }

    /** "1 m", "40 – 60 cm", "Yes": a product's specifications for its page, in definition order. */
    public function specifications(int $productId): array
    {
        $rows = DB::table('plant_attribute_product as v')
            ->join('plant_attribute_definitions as d', 'd.id', '=', 'v.definition_id')
            ->leftJoin('plant_attribute_terms as t', 't.id', '=', 'v.term_id')
            ->where('v.product_id', $productId)
            ->where('d.is_active', true)
            ->whereNull('d.deleted_at')
            ->orderBy('d.sort')->orderBy('d.name')->orderBy('t.sort')->orderBy('t.value')
            ->get(['d.id', 'd.code', 'd.name', 'd.type', 'd.unit', 't.value as term', 'v.value_text', 'v.value_number', 'v.value_number_max']);
        $out = [];
        foreach ($rows as $r) {
            $key = (int) $r->id;
            $out[$key] ??= ['code' => $r->code, 'name' => $r->name, 'type' => $r->type, 'unit' => $r->unit, 'values' => []];
            if ($r->term !== null) {
                $out[$key]['values'][] = $r->term;
            } elseif ($r->type === 'boolean' && $r->value_number !== null) {
                $out[$key]['values'][] = (float) $r->value_number ? 'Yes' : 'No';
            } elseif ($r->value_number !== null) {
                $text = $this->num((float) $r->value_number);
                if ($r->type === 'range' && $r->value_number_max !== null && (float) $r->value_number_max !== (float) $r->value_number) {
                    $text .= ' – ' . $this->num((float) $r->value_number_max);
                }
                $out[$key]['values'][] = $r->unit ? "{$text} {$r->unit}" : $text;
            } elseif ($r->value_text !== null && trim($r->value_text) !== '') {
                $out[$key]['values'][] = (string) $r->value_text;
            }
        }

        return array_values(array_filter($out, fn ($s) => $s['values']));
    }

    private function isEmpty(PlantAttributeDefinition $def, array $entry): bool
    {
        if (in_array($def->type, PlantAttributeDefinition::TERM_TYPES, true)) {
            return !array_filter((array) ($entry['term_ids'] ?? []));
        }
        $value = $entry['value'] ?? null;

        return $value === null || (is_string($value) && trim($value) === '') || (is_array($value) && !$value);
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
