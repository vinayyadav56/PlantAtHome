<?php

namespace Marvel\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * A GS1 barcode number (GTIN-8, UPC-A 12, EAN-13 or GTIN-14): digits only, with a valid mod-10
 * check digit, so a mistyped barcode is caught at the form instead of at a marketplace feed.
 */
class Gtin implements Rule
{
    public function passes($attribute, $value): bool
    {
        $digits = (string) $value;
        if (!preg_match('/^(\d{8}|\d{12,14})$/D', $digits)) {
            return false;
        }
        // Weights 3,1,3,1… from the digit left of the check digit.
        $sum = 0;
        foreach (str_split(strrev(substr($digits, 0, -1))) as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) substr($digits, -1);
    }

    public function message(): string
    {
        return 'The :attribute must be a valid GTIN: 8, 12, 13 or 14 digits ending in the right check digit.';
    }
}
