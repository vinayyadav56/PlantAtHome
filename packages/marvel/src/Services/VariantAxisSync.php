<?php

namespace Marvel\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeValue;
use Marvel\Database\Models\PlantAttributeDefinition;

/**
 * A product-attribute definition flagged `is_variant_axis` (a tool's Colour, Capacity) is the
 * admin's place to manage a variant axis; variants themselves stay on the existing engine
 * (legacy attributes/attribute_values = axes, variation_options = variants). This keeps ONE
 * global legacy attribute per axis definition in step with it: slug = the definition's code,
 * name = its name, one value per option (matched by slug, ordered by the option's sort).
 *
 * Values are never deleted: a delete cascades through attribute_product and strands the
 * variants that store the value's text. A retired option keeps its value.
 */
class VariantAxisSync
{
    public function sync(PlantAttributeDefinition $def): void
    {
        // A variant has exactly one value per axis, so only single-choice definitions qualify.
        if (!$def->is_variant_axis || $def->type !== 'single') {
            return;
        }
        $slug = $def->code ?: $def->slug;
        $attribute = $def->attribute_id ? Attribute::find($def->attribute_id) : null;

        // Never adopt or share an existing attribute: the plants' Size master (and its delivery
        // charges) or the demo Color would start answering to this definition.
        $clash = Attribute::where('language', 'en')
            ->when($attribute, fn ($q) => $q->whereKeyNot($attribute->id))
            ->where(fn ($q) => $q->whereRaw('LOWER(slug) = ?', [mb_strtolower($slug)])
                ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($def->name)]))
            ->value('name');
        if ($clash !== null) {
            throw ValidationException::withMessages([
                'name' => ["\"{$clash}\" already exists under Attributes: give this variant option a name and code of its own."],
            ]);
        }

        if ($attribute) {
            $attribute->update(['name' => $def->name]);   // the slug (= code) is never rewritten
        } else {
            $attribute = Attribute::create(['slug' => $slug, 'name' => $def->name, 'language' => 'en', 'shop_id' => null]);
        }
        foreach ($def->terms()->get() as $term) {
            AttributeValue::updateOrCreate(
                ['attribute_id' => $attribute->id, 'slug' => $term->slug],
                ['value' => $term->value, 'sort_order' => $term->sort, 'language' => 'en'],
            );
        }
        if ((int) $def->attribute_id !== (int) $attribute->id) {
            $def->update(['attribute_id' => $attribute->id]);
        }
    }

    /**
     * How many products have variants on this axis — or, given an option's slug, on that option.
     * Variant rows store the axis name and value as TEXT, so they count whether or not the
     * product still links the value (attribute_product).
     */
    public function productsUsing(PlantAttributeDefinition $def, ?string $termSlug = null): int
    {
        $attribute = $def->attribute_id ? Attribute::find($def->attribute_id) : null;
        $values = $attribute
            ? AttributeValue::where('attribute_id', $attribute->id)
                ->when($termSlug !== null, fn ($q) => $q->where('slug', $termSlug))->pluck('value', 'id')
            : collect();
        if ($values->isEmpty()) {
            return 0;
        }
        $products = DB::table('attribute_product')->whereIn('attribute_value_id', $values->keys())
            ->distinct()->pluck('product_id')->all();

        $lower = fn ($s) => mb_strtolower(trim((string) $s));
        $name = $lower($attribute->name);
        $texts = $values->map($lower)->flip();
        // ponytail: a LIKE pre-filter on the axis name, then exact (case-insensitive) matching in
        // PHP; a stored option key column would make this an index lookup if it ever gets hot.
        $rows = DB::table('variation_options')->whereNotIn('product_id', $products)
            ->whereRaw('LOWER(CAST(options AS CHAR)) LIKE ?', ['%' . preg_replace('/[^a-z0-9]+/', '%', $name) . '%'])
            ->get(['product_id', 'options']);
        foreach ($rows as $row) {
            foreach ((array) json_decode((string) $row->options, true) as $option) {
                if (is_array($option) && $lower($option['name'] ?? '') === $name && isset($texts[$lower($option['value'] ?? '')])) {
                    $products[] = $row->product_id;
                    break;
                }
            }
        }

        return count(array_unique($products));
    }

    /**
     * The legacy Attributes screens must not edit an attribute an axis definition owns, or its
     * values: the next sync would undo the edit, and a delete strands the variants.
     */
    public static function assertUnmanaged(int ...$attributeIds): void
    {
        try {
            $managed = PlantAttributeDefinition::whereIn('attribute_id', array_filter($attributeIds))->exists();
        } catch (QueryException $e) {
            return; // before 2026_10_15_000200 no attribute is linked
        }
        abort_if($managed, 422, 'Managed in Product Attributes');
    }
}
