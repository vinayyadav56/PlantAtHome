<?php

namespace Marvel\Services;

use Marvel\Database\Models\PlantAttributeDefinition;

/**
 * The storefront's attribute filter: `attr=<code>:<a>,<b>|<code>:<min>-<max>|<code>:true`.
 *
 * OR within one attribute (blade material steel OR carbon steel), AND across attributes
 * (… AND capacity 2–5 L). Term attributes match term slugs; number and range attributes a
 * numeric window (open ends allowed: "5-" or "-20"; a range value matches when it overlaps);
 * boolean attributes true/false; text attributes the exact value. Unknown or retired
 * attributes are ignored, so an old link never empties a page.
 *
 * The plants' `terms` param keeps its own (OR-only) behaviour; this is a separate param.
 */
class AttributeFilter
{
    public static function apply($query, ?string $attr, string $productIdColumn = 'products.id')
    {
        $groups = self::parse($attr);
        if (!$groups) {
            return $query;
        }
        $definitions = PlantAttributeDefinition::active()->whereIn('code', array_keys($groups))->get()->keyBy('code');

        foreach ($groups as $code => $raw) {
            $def = $definitions->get($code);
            if (!$def) {
                continue;
            }
            $query->whereExists(function ($q) use ($def, $raw, $productIdColumn) {
                $q->selectRaw('1')->from('plant_attribute_product as pav')
                    ->whereColumn('pav.product_id', $productIdColumn)
                    ->where('pav.definition_id', $def->id);

                if (in_array($def->type, PlantAttributeDefinition::TERM_TYPES, true)) {
                    $slugs = array_values(array_filter(array_map('trim', explode(',', $raw))));
                    $q->whereIn('pav.term_id', function ($t) use ($def, $slugs) {
                        $t->select('id')->from('plant_attribute_terms')
                            ->where('definition_id', $def->id)->whereIn('slug', $slugs ?: ['']);
                    });
                } elseif ($def->type === 'boolean') {
                    $q->where('pav.value_number', filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
                } elseif (in_array($def->type, ['number', 'range'], true)) {
                    [$min, $max] = self::window($raw);
                    if ($def->type === 'range') {
                        // overlap: the product's span reaches the window
                        if ($min !== null) {
                            // Plain column comparisons (an expression has no numeric affinity in
                            // sqlite, so COALESCE(...) >= ? compared as text there).
                            $q->where(fn ($w) => $w->where('pav.value_number_max', '>=', $min)
                                ->orWhere(fn ($x) => $x->whereNull('pav.value_number_max')->where('pav.value_number', '>=', $min)));
                        }
                        if ($max !== null) {
                            $q->where('pav.value_number', '<=', $max);
                        }
                    } else {
                        if ($min !== null) {
                            $q->where('pav.value_number', '>=', $min);
                        }
                        if ($max !== null) {
                            $q->where('pav.value_number', '<=', $max);
                        }
                    }
                } else {
                    $q->where('pav.value_text', $raw);
                }
            });
        }

        return $query;
    }

    /** "code:value|code:value" → [code => value]; malformed parts are dropped. */
    public static function parse(?string $attr): array
    {
        $groups = [];
        foreach (explode('|', (string) $attr) as $part) {
            [$code, $value] = array_pad(explode(':', $part, 2), 2, '');
            $code = trim($code);
            $value = trim($value);
            if ($code === '' || $value === '' || !preg_match('/^[a-z0-9-]+$/', $code)) {
                continue;
            }
            $groups[$code] = $value;
        }

        return $groups;
    }

    /** "2-5" → [2.0, 5.0]; "2-" → [2.0, null]; "-5" → [null, 5.0]. */
    private static function window(string $raw): array
    {
        if (!preg_match('/^\s*(\d+(?:\.\d+)?)?\s*-\s*(\d+(?:\.\d+)?)?\s*$/', $raw, $m)) {
            return is_numeric($raw) ? [(float) $raw, (float) $raw] : [null, null];
        }

        return [isset($m[1]) && $m[1] !== '' ? (float) $m[1] : null, isset($m[2]) && $m[2] !== '' ? (float) $m[2] : null];
    }
}
