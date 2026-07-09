<?php

namespace Marvel\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Marvel\Database\Models\Shop;

/**
 * A vendor phone number may belong to only one vendor — but phone numbers live
 * in TWO places with inconsistent formats: the newer shops.mobile column
 * (10 digits) and the legacy settings JSON `$.contact` path ("919996469046",
 * country-code prefixed). A plain unique index on the column therefore misses
 * every pre-existing vendor. This rule normalizes both sides to their LAST 10
 * DIGITS and checks both storage paths. Pass the shop id being updated to
 * exclude that vendor's own row.
 */
class UniquePhone implements ValidationRule
{
    public function __construct(private ?int $excludeShopId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $last10 = self::normalize($value);
        if ($last10 === null) {
            return; // empty / too-short values are format-checked elsewhere
        }

        // SQL prefilter (cheap LIKE on both paths), then confirm with an exact
        // normalized comparison in PHP — avoids REGEXP_REPLACE portability
        // concerns while staying index-friendly enough at this table's size.
        $candidates = Shop::query()
            ->where(function ($q) use ($last10) {
                $q->where('mobile', 'like', "%{$last10}")
                    ->orWhereRaw(
                        "JSON_UNQUOTE(JSON_EXTRACT(settings, '$.contact')) LIKE ?",
                        ["%{$last10}%"]
                    );
            })
            ->when($this->excludeShopId !== null, fn ($q) => $q->where('id', '!=', $this->excludeShopId))
            ->get(['id', 'mobile', 'settings']);

        foreach ($candidates as $shop) {
            $settings = is_array($shop->settings) ? $shop->settings : [];
            if (
                self::normalize($shop->mobile) === $last10 ||
                self::normalize($settings['contact'] ?? null) === $last10
            ) {
                $fail('Another vendor is already registered with this phone number.');

                return;
            }
        }
    }

    /** Strip non-digits and keep the last 10 (drops +91/91/0 prefixes). */
    public static function normalize(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? ''));
        if (strlen($digits) < 10) {
            return null;
        }

        return substr($digits, -10);
    }
}
