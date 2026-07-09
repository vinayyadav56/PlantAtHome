<?php

namespace Marvel\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Marvel\Database\Models\Balance;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\User;

/**
 * An email may belong to only one vendor/user across the platform — but
 * vendor emails live in THREE places: users.email (login accounts),
 * balances.payment_info->$.email (account-holder email) and
 * shops.settings->$.notifications.email (ops email). Checking only
 * users.email let duplicates of older vendors (whose emails exist only in
 * the JSON paths) sail through. Pass the shop id being updated to exclude
 * that vendor's own rows (and its owner's user account).
 */
class EmailInUse implements ValidationRule
{
    public function __construct(private ?int $excludeShopId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = strtolower(trim((string) ($value ?? '')));
        if ($email === '') {
            return;
        }

        $excludedOwnerId = null;
        if ($this->excludeShopId !== null) {
            $excludedOwnerId = Shop::whereKey($this->excludeShopId)->value('owner_id');
        }

        $inUsers = User::whereRaw('LOWER(email) = ?', [$email])
            ->when($excludedOwnerId !== null, fn ($q) => $q->where('id', '!=', $excludedOwnerId))
            ->exists();

        $inBalances = Balance::whereRaw(
            "LOWER(JSON_UNQUOTE(JSON_EXTRACT(payment_info, '$.email'))) = ?",
            [$email]
        )
            ->when($this->excludeShopId !== null, fn ($q) => $q->where('shop_id', '!=', $this->excludeShopId))
            ->exists();

        $inSettings = Shop::whereRaw(
            "LOWER(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.notifications.email'))) = ?",
            [$email]
        )
            ->when($this->excludeShopId !== null, fn ($q) => $q->where('id', '!=', $this->excludeShopId))
            ->exists();

        if ($inUsers || $inBalances || $inSettings) {
            $fail('This email is already in use by another vendor or user.');
        }
    }
}
