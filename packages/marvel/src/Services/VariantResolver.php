<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves a cart/order line to its entry in the variant master (the Size
 * attribute's attribute_values row), which is where a size's code and its
 * delivery charge live.
 *
 * Why this is not a single join: a variation_option records the size it is in
 * its `options` JSON, and that JSON has two shapes in production.
 *
 *   admin-built   [{"name":"Size","value":"Large","id":42}]   id = attribute_values.id
 *   seeded/CLI    [{"name":"Size","value":"Large"}]           no id at all
 *
 * PlantAtHomePotSeeder and both ApplySizePricing commands write the second
 * shape, so id-only matching would silently miss a large part of the catalogue
 * and quietly charge those lines nothing for delivery. Hence: trust the id when
 * it is present AND belongs to a Size attribute (the 2026-09-17 merge re-pointed
 * pivots but never rewrote this JSON, so an id can outlive the row it named),
 * otherwise fall back to the value text, then to the option title.
 *
 * Everything is loaded in two queries and memoised for the request.
 */
class VariantResolver
{
    /** @var array<int, object|null> variation_option_id => size row (null = resolved to nothing) */
    private array $memo = [];

    /** @var array<int, object>|null attribute_values.id => row, for every Size attribute */
    private ?array $sizeValues = null;

    /** @var array<string, object>|null lowercased value text => row */
    private ?array $sizeValuesByText = null;

    private ?bool $available = null;

    /**
     * Size rows for a batch of lines, keyed "productId:variationOptionId".
     *
     * @param  array $lines each with product_id and (optionally) variation_option_id
     * @return array<string, object|null>
     */
    public function mapForLines(array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            $vid = (int) ($line['variation_option_id'] ?? 0);
            if ($vid > 0 && !array_key_exists($vid, $this->memo)) {
                $ids[$vid] = true;
            }
        }
        $this->warm(array_keys($ids));

        $out = [];
        foreach ($lines as $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            $vid = (int) ($line['variation_option_id'] ?? 0);
            $out[$pid . ':' . $vid] = $vid > 0 ? ($this->memo[$vid] ?? null) : null;
        }

        return $out;
    }

    /** The size row for one variation option, or null when it has no resolvable size. */
    public function sizeValueFor(?int $variationOptionId): ?object
    {
        $vid = (int) $variationOptionId;
        if ($vid <= 0) {
            return null;
        }
        if (!array_key_exists($vid, $this->memo)) {
            $this->warm([$vid]);
        }

        return $this->memo[$vid] ?? null;
    }

    /** Per-UNIT delivery charge configured for a variation option, or null when unset. */
    public function deliveryChargeFor(?int $variationOptionId): ?float
    {
        $row = $this->sizeValueFor($variationOptionId);
        $charge = $row->delivery_charge ?? null;

        return $charge === null ? null : (float) $charge;
    }

    /** @param int[] $variationOptionIds */
    private function warm(array $variationOptionIds): void
    {
        if (!$variationOptionIds || !$this->available()) {
            foreach ($variationOptionIds as $vid) {
                $this->memo[$vid] = null;
            }

            return;
        }

        $this->loadSizeValues();

        $rows = DB::table('variation_options')
            ->whereIn('id', $variationOptionIds)
            ->get(['id', 'title', 'options']);

        foreach ($rows as $row) {
            $this->memo[(int) $row->id] = $this->resolve($row);
        }
        // Anything the query did not return (deleted option) resolves to nothing,
        // and must still be memoised or every call re-queries for it.
        foreach ($variationOptionIds as $vid) {
            if (!array_key_exists($vid, $this->memo)) {
                $this->memo[$vid] = null;
            }
        }
    }

    private function resolve(object $variationOption): ?object
    {
        $options = json_decode((string) ($variationOption->options ?? ''), true);

        if (is_array($options)) {
            // 1. an id that still names a Size value
            foreach ($options as $option) {
                $id = (int) ($option['id'] ?? 0);
                if ($id > 0 && isset($this->sizeValues[$id])) {
                    return $this->sizeValues[$id];
                }
            }
            // 2. the value text
            foreach ($options as $option) {
                $hit = $this->byText($option['value'] ?? null);
                if ($hit) {
                    return $hit;
                }
            }
        }

        // 3. the title, which for a single-axis product IS the size ("Large"),
        //    and for a multi-axis one is slash-joined ("Large/Terracotta").
        foreach (explode('/', (string) ($variationOption->title ?? '')) as $part) {
            $hit = $this->byText($part);
            if ($hit) {
                return $hit;
            }
        }

        return null;
    }

    private function byText(?string $text): ?object
    {
        $key = strtolower(trim((string) $text));

        return $key === '' ? null : ($this->sizeValuesByText[$key] ?? null);
    }

    private function loadSizeValues(): void
    {
        if ($this->sizeValues !== null) {
            return;
        }

        $this->sizeValues = [];
        $this->sizeValuesByText = [];

        $rows = DB::table('attribute_values')
            ->join('attributes', 'attributes.id', '=', 'attribute_values.attribute_id')
            ->where(function ($q) {
                $q->whereRaw('LOWER(attributes.slug) = ?', ['size'])->orWhereRaw('LOWER(attributes.name) = ?', ['size']);
            })
            ->get([
                'attribute_values.id',
                'attribute_values.value',
                'attribute_values.code',
                'attribute_values.sort_order',
                'attribute_values.delivery_charge',
            ]);

        foreach ($rows as $row) {
            $this->sizeValues[(int) $row->id] = $row;
            $key = strtolower(trim((string) $row->value));
            // First writer wins: production has carried duplicate Size values, and
            // they agree on the text — which is the only thing being matched here.
            if ($key !== '' && !isset($this->sizeValuesByText[$key])) {
                $this->sizeValuesByText[$key] = $row;
            }
        }
    }

    /**
     * The master's columns land with a migration, and a deploy runs those after
     * the new code is already serving — so this has to be asked, not assumed.
     *
     * Memoised per INSTANCE, deliberately not in a static: a resolver lives for
     * one operation, whereas a static would freeze the answer for the life of
     * the worker. A php-fpm worker that happened to ask between the code landing
     * and the migration finishing would then price delivery off the fallback
     * until it recycled, and that is money.
     */
    private function available(): bool
    {
        if ($this->available === null) {
            try {
                $this->available = Schema::hasTable('variation_options')
                    && Schema::hasTable('attribute_values')
                    && Schema::hasColumn('attribute_values', 'delivery_charge');
            } catch (\Throwable $e) {
                $this->available = false;
            }
        }

        return $this->available;
    }
}
