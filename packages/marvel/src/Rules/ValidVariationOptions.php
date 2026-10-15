<?php

namespace Marvel\Rules;

use Illuminate\Contracts\Validation\ImplicitRule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Enums\ProductType;

/**
 * A product save's variants (`variation_options: {upsert: [rows], delete: [ids]}`), checked before
 * anything is written: updateProduct has no transaction. A variant IS its set of (axis name, value)
 * pairs, compared case-insensitively and in any order, never by position.
 *
 * - each row of a variable product names every chosen axis once, with one of its chosen values;
 * - no combination twice, in the payload or against stored rows the save leaves alone;
 * - an id is one of this product's variants, and a variant with orders or seller prices keeps its
 *   combination (the admin used to bind ids by row position, which re-pointed them);
 * - a SKU is used by no other variant (blank = generated on save);
 * - a variant with orders or seller prices is never deleted, also not by a switch to simple
 *   (updateProduct then deletes every variant).
 *
 * Implicit, so the switch-to-simple guard runs even when a client leaves `variation_options` out.
 * The first message names the variant: the admin toasts only the first one.
 */
class ValidVariationOptions implements ImplicitRule
{
    /** A reference here means the variant is in use (the BLOCKING list of 2026_10_08_000200). */
    private const REFERENCED_BY = ['order_product', 'order_items', 'reviews', 'vendor_product_prices', 'inventory_transactions'];

    /** How the per-row rules name a variant's fields: "The Red / L price must be greater than 0." */
    private const FIELD_NAMES = [
        'id' => 'id', 'title' => 'title', 'options' => 'options', 'price' => 'price', 'sale_price' => 'sale price',
        'quantity' => 'quantity', 'is_disable' => 'on/off switch', 'sku' => 'SKU', 'gtin' => 'GTIN', 'mpn' => 'MPN',
    ];

    private array $errors = [];

    /**
     * @param mixed       $variations  the request's `variations` ([{attribute_value_id}] or ids); null = not sent, the stored axes stay
     * @param int|null    $productId   the product being edited; null on create
     * @param string|null $productType the type the save stores
     */
    public function __construct(private $variations, private ?int $productId, private ?string $productType)
    {
    }

    /** Type and range rules for each row; both product requests add them beside this rule. */
    public static function rowRules(bool $create): array
    {
        return [
            // distinct HERE: on `variations.*` it never sees the ids inside {attribute_value_id} objects.
            'variations.*.attribute_value_id'       => ['integer', 'distinct', 'exists:attribute_values,id'],
            'variation_options.upsert'              => ['array'],
            'variation_options.upsert.*.id'         => $create ? ['prohibited'] : ['nullable', 'integer'],
            'variation_options.upsert.*.title'      => ['nullable', 'string', 'max:191'],
            'variation_options.upsert.*.options'    => ['required', 'array'],
            'variation_options.upsert.*.price'      => ['required', 'numeric', 'gt:0'],
            'variation_options.upsert.*.sale_price' => ['nullable', 'numeric', 'gt:0', 'lt:variation_options.upsert.*.price'],
            'variation_options.upsert.*.quantity'   => ['required', 'integer', 'min:0'],
            'variation_options.upsert.*.is_disable' => ['boolean'],
            'variation_options.upsert.*.sku'        => ['nullable', 'string', 'max:191'],
            'variation_options.upsert.*.gtin'       => ['nullable', 'string', new Gtin()],
            'variation_options.upsert.*.mpn'        => ['nullable', 'string', 'max:64'],
            'variation_options.delete'              => ['array'],
            'variation_options.delete.*'            => ['integer'],
        ];
    }

    /** Display names for the per-row rules, so their messages name the variant too. */
    public static function attributeNames($variationOptions): array
    {
        $names = ['variations.*.attribute_value_id' => 'option value'];
        $upsert = data_get($variationOptions, 'upsert');
        foreach (is_array($upsert) ? $upsert : [] as $i => $row) {
            $label = self::label($row, (int) $i);
            foreach (self::FIELD_NAMES as $field => $name) {
                $names["variation_options.upsert.{$i}.{$field}"] = "{$label} {$name}";
            }
        }

        return $names;
    }

    public function passes($attribute, $value): bool
    {
        try {
            $this->errors = $this->check(is_array($value) ? $value : []);
        } catch (QueryException $e) {
            // Tables not migrated yet (deploys migrate after the code serves): never block a save.
            return true;
        }

        return $this->errors === [];
    }

    public function message(): array
    {
        return $this->errors;
    }

    private function check(array $value): array
    {
        $errors = [];
        $upsert = array_values(array_filter((array) ($value['upsert'] ?? []), 'is_array'));
        $deleteIds = array_map('intval', array_filter((array) ($value['delete'] ?? []), 'is_numeric'));
        $stored = $this->productId
            ? DB::table('variation_options')->where('product_id', $this->productId)->get(['id', 'title', 'options', 'sku'])->keyBy(fn ($r) => (int) $r->id)
            : collect();

        $changed = []; // id => new label, for stored rows the save gives another combination
        if ($this->productType === ProductType::VARIABLE) {
            $axes = $this->axes();
            $touched = array_merge($deleteIds, array_map(fn ($r) => (int) ($r['id'] ?? 0), $upsert));
            $seen = [];
            foreach ($stored as $id => $row) {
                if (!in_array($id, $touched, true)) {
                    $seen[self::key(self::options($row->options))] = true;
                }
            }
            foreach ($upsert as $i => $row) {
                $label = self::label($row, $i);
                $options = self::options($row['options'] ?? null);
                $id = (int) ($row['id'] ?? 0);
                if ($id && $this->productId && !$stored->has($id)) {
                    $errors[] = "{$label} is not one of this product's variants (id {$id}) — reload the page and save again.";
                    continue;
                }
                if ($rowErrors = $this->optionErrors($options, $axes, $label)) {
                    array_push($errors, ...$rowErrors);
                    continue;
                }
                $key = self::key($options);
                if (isset($seen[$key])) {
                    $errors[] = "{$label} appears twice.";
                }
                $seen[$key] = true;
                if ($id && $this->productId && self::key(self::options($stored[$id]->options)) !== $key) {
                    $changed[$id] = $label;
                }
            }
        }

        // updateProduct deletes every variant of a product saved as simple.
        $deleting = $this->productType === ProductType::SIMPLE
            ? $stored->keys()->all()
            : array_values(array_intersect($deleteIds, $stored->keys()->all()));
        $used = $this->referenced(array_merge($deleting, array_keys($changed)));
        foreach (array_unique($deleting) as $id) {
            if (isset($used[$id])) {
                $errors[] = "'" . self::label($stored[$id]) . "' has orders or seller prices — switch it off instead of deleting it.";
            }
        }
        foreach ($changed as $id => $label) {
            if (isset($used[$id])) {
                $errors[] = "'" . self::label($stored[$id]) . "' has orders or seller prices, so it cannot become {$label} — switch it off and add {$label} as a new variant.";
            }
        }

        return array_merge($errors, $this->skuErrors($upsert, $stored));
    }

    /** One message per broken rule of this row's options against the chosen axes. */
    private function optionErrors(array $options, array $axes, string $label): array
    {
        $errors = [];
        $picked = [];
        foreach ($options as [$name, $value]) {
            $axis = mb_strtolower($name);
            if (!isset($axes[$axis])) {
                $errors[] = "{$label}: {$name} is not one of this product's options.";
            } elseif (isset($picked[$axis])) {
                $errors[] = "{$label}: {$axes[$axis]['name']} is given twice.";
            } elseif (!isset($axes[$axis]['values'][mb_strtolower($value)])) {
                $errors[] = "{$value} is not a {$axes[$axis]['name']} value ({$label}).";
            }
            $picked[$axis] = true;
        }
        foreach (array_diff_key($axes, $picked) as $axis) {
            $errors[] = "{$label} needs a {$axis['name']} value.";
        }

        return $errors;
    }

    /**
     * A SKU the variant already has is kept as it is (older duplicates included); any other must be
     * free across ALL variants. Blank = generated on save.
     */
    private function skuErrors(array $upsert, Collection $stored): array
    {
        $errors = [];
        $wanted = []; // lower sku => [sku, row id]
        foreach ($upsert as $row) {
            $sku = is_scalar($row['sku'] ?? null) ? trim((string) $row['sku']) : '';
            $id = (int) ($row['id'] ?? 0);
            $lower = mb_strtolower($sku);
            if ($sku === '' || ($stored->has($id) && mb_strtolower(trim((string) $stored[$id]->sku)) === $lower)) {
                continue;
            }
            if (isset($wanted[$lower])) {
                $errors[] = "SKU {$sku} is already used.";
                continue;
            }
            $wanted[$lower] = [$sku, $id];
        }
        if ($wanted) {
            foreach (DB::table('variation_options')->whereIn('sku', array_column($wanted, 0))->get(['id', 'sku']) as $taken) {
                [$sku, $id] = $wanted[mb_strtolower((string) $taken->sku)] ?? [null, 0];
                if ($sku !== null && (int) $taken->id !== $id) {
                    $errors[] = "SKU {$sku} is already used.";
                    unset($wanted[mb_strtolower($sku)]);
                }
            }
        }

        return $errors;
    }

    /** [lower axis name => ['name' => name, 'values' => [lower value => true]]] of the chosen values. */
    private function axes(): array
    {
        $query = DB::table('attribute_values')->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id');
        if ($this->variations === null) {
            if (!$this->productId) {
                return [];
            }
            $query->join('attribute_product', 'attribute_product.attribute_value_id', '=', 'attribute_values.id')
                ->where('attribute_product.product_id', $this->productId);
        } else {
            $ids = [];
            foreach ((array) $this->variations as $v) {
                $id = is_array($v) ? ($v['attribute_value_id'] ?? null) : $v;
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
            if (!$ids) {
                return [];
            }
            $query->whereIn('attribute_values.id', $ids);
        }
        $axes = [];
        foreach ($query->get(['attributes.name', 'attribute_values.value']) as $r) {
            $axis = mb_strtolower(trim((string) $r->name));
            $axes[$axis]['name'] = trim((string) $r->name);
            $axes[$axis]['values'][mb_strtolower(trim((string) $r->value))] = true;
        }

        return $axes;
    }

    /** @return array<int, true> the ids an order, review, seller price or stock movement points at */
    private function referenced(array $ids): array
    {
        $used = [];
        if (!$ids) {
            return $used;
        }
        foreach (self::REFERENCED_BY as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'variation_option_id')) {
                foreach (DB::table($table)->whereIn('variation_option_id', $ids)->distinct()->pluck('variation_option_id') as $id) {
                    $used[(int) $id] = true;
                }
            }
        }

        return $used;
    }

    /** A row's options as [[name, value]], trimmed, from the payload array or the stored JSON. */
    private static function options($options): array
    {
        if (is_string($options)) {
            $options = json_decode($options, true);
        }
        $out = [];
        foreach (is_array($options) ? $options : [] as $o) {
            $name = data_get($o, 'name');
            $value = data_get($o, 'value');
            if (is_scalar($name) && is_scalar($value)) {
                $out[] = [trim((string) $name), trim((string) $value)];
            }
        }

        return $out;
    }

    /** The combination's identity: its (name, value) pairs, lower-cased, in any order. */
    private static function key(array $options): string
    {
        $pairs = array_map(fn ($o) => [mb_strtolower($o[0]), mb_strtolower($o[1])], $options);
        sort($pairs);

        return serialize($pairs);
    }

    /** "Red / L": the variant's option values, else its title, else its position. */
    private static function label($row, int $i = 0): string
    {
        $values = array_filter(array_column(self::options(data_get($row, 'options')), 1), 'strlen');
        $title = data_get($row, 'title');
        if ($values) {
            return implode(' / ', $values);
        }

        return is_scalar($title) && trim((string) $title) !== '' ? trim((string) $title) : 'Variant ' . ($i + 1);
    }
}
