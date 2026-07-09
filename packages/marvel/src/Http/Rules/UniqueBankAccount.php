<?php

namespace Marvel\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Marvel\Database\Models\Balance;

/**
 * A bank account number (balance.payment_info.account, stored inside the
 * balances.payment_info JSON) may belong to only one vendor. Pass the shop id
 * being updated to exclude that vendor's own balance row from the check.
 */
class UniqueBankAccount implements ValidationRule
{
    public function __construct(private ?int $excludeShopId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Empty / missing account numbers are allowed (banking details are optional).
        if ($value === null || trim((string) $value) === '') {
            return;
        }
        $query = Balance::whereRaw(
            "JSON_UNQUOTE(JSON_EXTRACT(payment_info, '$.account')) = ?",
            [(string) $value]
        );
        if ($this->excludeShopId !== null) {
            $query->where('shop_id', '!=', $this->excludeShopId);
        }
        if ($query->exists()) {
            $fail('This bank account number is already registered to another vendor.');
        }
    }
}
